<?php
declare(strict_types=1);
namespace ImWiki\Controllers;
use ImWiki\Http\Request;use ImWiki\Repositories\UserRepository;use ImWiki\Security\Authorization;use ImWiki\Services\NotificationService;use ImWiki\Services\PresenceService;use ImWiki\View\View;use PDO;
final class PresenceController extends BaseController{
 public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly PresenceService $presence){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
 public function heartbeat(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);header('Content-Type: application/json; charset=utf-8');try{$others=$this->presence->heartbeat((int)$p['id'],$uid);echo json_encode(['ok'=>true,'users'=>array_map(static fn($u)=>['id'=>(int)$u['user_id'],'username'=>$u['username'],'name'=>trim((string)$u['name'])],$others)],JSON_UNESCAPED_UNICODE);}catch(\Throwable){http_response_code(403);echo json_encode(['ok'=>false]);}}
 public function leave(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);$this->presence->leave((int)$p['id'],$uid);http_response_code(204);}
}
