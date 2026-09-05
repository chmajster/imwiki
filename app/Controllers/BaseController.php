<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Security\Csrf;
use ImWiki\Services\NotificationService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;
use PDOException;

abstract class BaseController
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly string $prefix,
        protected readonly View $view,
        protected readonly UserRepository $users,
        protected readonly Authorization $authz,
        protected readonly ?NotificationService $notifications = null,
    ) {}

    protected function userId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    protected function requireAuth(): int
    {
        $id = $this->userId();
        if ($id <= 0 || !$this->users->find($id)) {
            Response::redirect(Url::to('/login'));
        }
        return $id;
    }

    protected function csrf(Request $request): void
    {
        if (!Csrf::validate((string)$request->input('_csrf', ''))) {
            http_response_code(419);
            echo $this->view->render('errors/419.php', $this->common());
            exit;
        }
    }

    protected function common(array $extra = []): array
    {
        $user = $this->userId() ? $this->users->find($this->userId()) : null;
        $notificationCount = 0;
        if ($user && $this->notifications) {
            $notificationCount = $this->notifications->unreadCount((int)$user['id']);
        }
        return array_merge([
            'currentUser'=>$user,
            'authz'=>$this->authz,
            'url'=>Url::class,
            'notificationCount'=>$notificationCount,
            'requestId'=>defined('IMWIKI_REQUEST_ID') ? IMWIKI_REQUEST_ID : '',
        ], $extra);
    }

    protected function audit(Request $request, string $action, string $type, ?int $resourceId, string $description, string $category='application', string $severity='info', array $metadata=[]): void
    {
        $uid = $this->userId() ?: null;
        $cleanMetadata = $this->sanitizeAuditMetadata($metadata);
        try {
            $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}audit_log` (user_id,action,category,severity,request_id,resource_type,resource_id,description,metadata_json,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
            $stmt->execute([$uid,$action,$category,$severity,defined('IMWIKI_REQUEST_ID')?IMWIKI_REQUEST_ID:null,$type,$resourceId,$description,$cleanMetadata?json_encode($cleanMetadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$request->ip(),$request->userAgent()]);
        } catch (PDOException) {
            // Upgrade compatibility: authentication must continue to work before migration 002 is applied.
            $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}audit_log` (user_id,action,resource_type,resource_id,description,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
            $stmt->execute([$uid,$action,$type,$resourceId,$description,$request->ip(),$request->userAgent()]);
        }
    }

    private function sanitizeAuditMetadata(array $metadata): array
    {
        $blocked = ['password','token','secret','csrf','session','authorization','cookie','db_password','smtp_password'];
        $walk = static function(array $data) use (&$walk,$blocked): array {
            $out=[];
            foreach($data as $key=>$value){
                $lower=mb_strtolower((string)$key);
                if(array_filter($blocked,static fn(string $needle): bool=>str_contains($lower,$needle))) continue;
                $out[$key]=is_array($value)?$walk($value):$value;
            }
            return $out;
        };
        return $walk($metadata);
    }
}
