<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use ImWiki\Support\Config;
use PDO;

final class NotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly Authorization $authz,
        private readonly PageRepository $pages,
        private readonly ?JobQueueService $jobs=null,
    ) {}

    public function create(int $userId, string $type, ?int $actorId, ?string $targetType, ?int $targetId, ?string $url, array $payload = []): int
    {
        if ($actorId !== null && $actorId === $userId && !str_starts_with($type, 'security.')) {
            return 0;
        }
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}notifications` (user_id,type,actor_id,target_type,target_id,url,payload_json,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        $stmt->execute([$userId, mb_substr($type,0,80), $actorId, $targetType, $targetId, $url, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null]);
        $id=(int)$this->pdo->lastInsertId();$prefs=$this->preferences($userId);if($this->jobs&&$prefs['email_mode']==='immediate'&&$this->categoryAllowed($prefs,$type))$this->jobs->enqueue('notification_email',['user_id'=>$userId,'notification_id'=>$id]);
        return $id;
    }


    public function notifyWatchers(string $event, int $actorId, int $pageId, int $spaceId, string $url, array $payload = []): void
    {
        $resources = $event === 'page.created' ? [['space',$spaceId]] : [['page',$pageId]];
        if ($event === 'page.updated') {
            $resources[] = ['space',$spaceId];
        }
        $seen = [];
        foreach ($resources as [$resourceType,$resourceId]) {
            $stmt = $this->pdo->prepare("SELECT user_id FROM `{$this->prefix}watchers` WHERE resource_type=? AND resource_id=?");
            $stmt->execute([$resourceType,$resourceId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $watcherId) {
                $watcherId = (int)$watcherId;
                if ($watcherId === $actorId || isset($seen[$watcherId])) continue;
                $seen[$watcherId] = true;
                $this->create($watcherId,$event,$actorId,'page',$pageId,$url,$payload);
            }
        }
    }

    public function notifyPageCommentWatchers(int $actorId, int $pageId, string $url, array $payload = []): void
    {
        $stmt = $this->pdo->prepare("SELECT user_id FROM `{$this->prefix}watchers` WHERE resource_type='page' AND resource_id=?");
        $stmt->execute([$pageId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $watcherId) {
            $watcherId = (int)$watcherId;
            if ($watcherId === $actorId) continue;
            $this->create($watcherId,'comment.created',$actorId,'page',$pageId,$url,$payload);
        }
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT id,type,target_type,target_id FROM `{$this->prefix}notifications` WHERE user_id=? AND read_at IS NULL ORDER BY id DESC LIMIT 250");
        $stmt->execute([$userId]);
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            if ($this->inAppAllowed($userId,(string)($row['type']??'')) && $this->visible($userId, $row['target_type'] ?: null, $row['target_id'] !== null ? (int)$row['target_id'] : null)) {
                $count++;
            }
        }
        return $count;
    }

    public function page(int $userId, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $wantedStart = ($page - 1) * $perPage;
        $visible = [];
        $offset = 0;
        $batch = 100;

        while (count($visible) < $wantedStart + $perPage + 1) {
            $stmt = $this->pdo->prepare("SELECT n.*, CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,'')) actor_name, a.username actor_username FROM `{$this->prefix}notifications` n LEFT JOIN `{$this->prefix}users` a ON a.id=n.actor_id WHERE n.user_id=:uid ORDER BY n.created_at DESC,n.id DESC LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':uid',$userId,PDO::PARAM_INT);
            $stmt->bindValue(':limit',$batch,PDO::PARAM_INT);
            $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if (!$rows) break;
            foreach ($rows as $row) {
                if (!$this->inAppAllowed($userId,(string)$row['type']) || !$this->visible($userId, $row['target_type'] ?: null, $row['target_id'] !== null ? (int)$row['target_id'] : null)) continue;
                $row['payload'] = $row['payload_json'] ? (json_decode((string)$row['payload_json'], true) ?: []) : [];
                $visible[] = $row;
            }
            if (count($rows) < $batch) break;
            $offset += $batch;
        }

        $slice = array_slice($visible, $wantedStart, $perPage);
        return [
            'items' => $slice,
            'page' => $page,
            'per_page' => $perPage,
            'has_previous' => $page > 1,
            'has_next' => count($visible) > $wantedStart + $perPage,
        ];
    }

    public function markRead(int $userId, int $notificationId): void
    {
        $stmt = $this->pdo->prepare("UPDATE `{$this->prefix}notifications` SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=? AND user_id=?");
        $stmt->execute([$notificationId,$userId]);
    }

    public function markAllRead(int $userId): void
    {
        $stmt = $this->pdo->prepare("UPDATE `{$this->prefix}notifications` SET read_at=UTC_TIMESTAMP() WHERE user_id=? AND read_at IS NULL");
        $stmt->execute([$userId]);
    }


    public function preferences(int $userId): array
    {
        $stmt=$this->pdo->prepare("SELECT notification_json FROM `{$this->prefix}user_preferences` WHERE user_id=?");$stmt->execute([$userId]);$raw=$stmt->fetchColumn();$data=$raw?json_decode((string)$raw,true):[];if(!is_array($data))$data=[];
        $defaultCats=['mentions'=>true,'comments'=>true,'replies'=>true,'watched_pages'=>true,'watched_spaces'=>true,'tasks'=>true,'security'=>true];
        return ['in_app'=>(bool)($data['in_app']??true),'email_mode'=>in_array((string)($data['email_mode']??'none'),['none','immediate','daily','weekly'],true)?(string)($data['email_mode']??'none'):'none','categories'=>array_replace($defaultCats,is_array($data['categories']??null)?$data['categories']:[])];
    }

    public function savePreferences(int $userId,bool $inApp,string $emailMode,array $categories): void
    {
        if(!in_array($emailMode,['none','immediate','daily','weekly'],true))$emailMode='none';$allowed=['mentions','comments','replies','watched_pages','watched_spaces','tasks','security'];$selected=array_fill_keys(array_intersect($allowed,$categories),true);$cat=[];foreach($allowed as $key)$cat[$key]=isset($selected[$key]);$json=json_encode(['in_app'=>$inApp,'email_mode'=>$emailMode,'categories'=>$cat],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}user_preferences` (user_id,notification_json,updated_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE notification_json=VALUES(notification_json),updated_at=UTC_TIMESTAMP()");$stmt->execute([$userId,$json]);
    }

    public function emailForNotification(int $userId,int $notificationId): ?array
    {
        $stmt=$this->pdo->prepare("SELECT n.*,u.email,u.first_name,u.username FROM `{$this->prefix}notifications` n JOIN `{$this->prefix}users` u ON u.id=n.user_id WHERE n.id=? AND n.user_id=? AND u.status='active' AND u.deleted_at IS NULL");$stmt->execute([$notificationId,$userId]);$n=$stmt->fetch();if(!$n||!$this->visible($userId,$n['target_type']?:null,$n['target_id']!==null?(int)$n['target_id']:null)||!$this->categoryAllowed($this->preferences($userId),(string)$n['type']))return null;$label=$this->label((string)$n['type']);$target=$this->targetLabel($n);$path=(string)($n['url']?:'/notifications');$absolute=rtrim((string)Config::get('app.url',''),'/').'/'.ltrim($path,'/');$subject='imWiki: '.$label.($target!==''?' — '.$target:'');$html='<p>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</p>'.($target!==''?'<p><strong>'.htmlspecialchars($target,ENT_QUOTES,'UTF-8').'</strong></p>':'').'<p><a href="'.htmlspecialchars($absolute,ENT_QUOTES,'UTF-8').'">Otwórz w imWiki</a></p>';$text=$label.($target!==''?"\n".$target:'')."\n".$absolute;return ['to'=>(string)$n['email'],'subject'=>$subject,'html'=>$html,'text'=>$text];
    }

    public function hasDigestItems(int $userId,string $from,string $to):bool
    {
        $stmt=$this->pdo->prepare("SELECT id,type,target_type,target_id FROM `{$this->prefix}notifications` WHERE user_id=? AND created_at>? AND created_at<=? ORDER BY id DESC LIMIT 100");$stmt->execute([$userId,$from,$to]);$prefs=$this->preferences($userId);foreach($stmt->fetchAll() as $n)if($this->categoryAllowed($prefs,(string)$n['type'])&&$this->visible($userId,$n['target_type']?:null,$n['target_id']!==null?(int)$n['target_id']:null))return true;return false;
    }

    public function digestEmail(int $userId,string $from,string $to):?array
    {
        $user=$this->pdo->prepare("SELECT email,first_name,username FROM `{$this->prefix}users` WHERE id=? AND status='active' AND deleted_at IS NULL");$user->execute([$userId]);$u=$user->fetch();if(!$u)return null;$stmt=$this->pdo->prepare("SELECT * FROM `{$this->prefix}notifications` WHERE user_id=? AND created_at>? AND created_at<=? ORDER BY created_at DESC,id DESC LIMIT 100");$stmt->execute([$userId,$from,$to]);$prefs=$this->preferences($userId);$items=[];foreach($stmt->fetchAll() as $n){if(!$this->categoryAllowed($prefs,(string)$n['type'])||!$this->visible($userId,$n['target_type']?:null,$n['target_id']!==null?(int)$n['target_id']:null))continue;$items[]=['label'=>$this->label((string)$n['type']),'target'=>$this->targetLabel($n),'url'=>(string)($n['url']?:'/notifications'),'created_at'=>(string)$n['created_at']];if(count($items)>=50)break;}if(!$items)return null;$base=rtrim((string)Config::get('app.url',''),'/');$html='<h1>imWiki — podsumowanie</h1><ul>';$text="imWiki — podsumowanie\n\n";foreach($items as $i){$absolute=$base.'/'.ltrim($i['url'],'/');$desc=$i['label'].($i['target']!==''?' — '.$i['target']:'');$html.='<li><a href="'.htmlspecialchars($absolute,ENT_QUOTES,'UTF-8').'">'.htmlspecialchars($desc,ENT_QUOTES,'UTF-8').'</a> <small>'.htmlspecialchars($i['created_at'],ENT_QUOTES,'UTF-8').'</small></li>';$text.='- '.$desc.' '.$absolute."\n";}$html.='</ul>';return ['to'=>(string)$u['email'],'subject'=>'imWiki — podsumowanie powiadomień','html'=>$html,'text'=>$text];
    }

    private function inAppAllowed(int $userId,string $type):bool{$p=$this->preferences($userId);return $p['in_app']&&$this->categoryAllowed($p,$type);}
    private function categoryAllowed(array $prefs,string $type):bool{$category=$this->category($type);return (bool)($prefs['categories'][$category]??true);}
    private function category(string $type):string{return match(true){str_starts_with($type,'mention')=>'mentions',str_contains($type,'reply')=>'replies',str_starts_with($type,'comment')=>'comments',str_starts_with($type,'page.')=>'watched_pages',str_starts_with($type,'space.')=>'watched_spaces',str_starts_with($type,'task.')=>'tasks',str_starts_with($type,'security.')=>'security',default=>'comments'};}
    private function label(string $type):string{return match($type){'mention'=>'Wspomniano o Tobie','comment.reply'=>'Odpowiedziano na komentarz','comment.created'=>'Nowy komentarz','page.updated'=>'Zmieniono obserwowaną stronę','page.created'=>'Utworzono stronę w obserwowanej przestrzeni','task.assigned'=>'Przypisano Ci zadanie','approval.requested'=>'Strona czeka na Twoją akceptację',default=>'Nowe zdarzenie w imWiki'};}
    private function targetLabel(array $n):string{if(($n['target_type']??null)==='page'&&$n['target_id']!==null){$p=$this->pages->find((int)$n['target_id']);return $p?(string)$p['title']:'';}if(($n['target_type']??null)==='space'&&$n['target_id']!==null){$s=$this->pdo->prepare("SELECT name FROM `{$this->prefix}spaces` WHERE id=? AND deleted_at IS NULL");$s->execute([(int)$n['target_id']]);return (string)($s->fetchColumn()?:'');}return '';}

    private function visible(int $userId, ?string $targetType, ?int $targetId): bool
    {
        if ($targetType === null || $targetId === null) return true;
        if ($targetType === 'page') {
            $page = $this->pages->find($targetId);
            return $page !== null && $this->authz->canViewPage($userId,$page);
        }
        if ($targetType === 'space') {
            return $this->authz->canViewSpace($userId,$targetId);
        }
        if ($targetType === 'task') {
            $stmt = $this->pdo->prepare("SELECT page_id FROM `{$this->prefix}tasks` WHERE id=?");
            $stmt->execute([$targetId]);
            $pageId = (int)($stmt->fetchColumn() ?: 0);
            if ($pageId <= 0) return false;
            $page = $this->pages->find($pageId);
            return $page !== null && $this->authz->canViewPage($userId,$page);
        }
        return true;
    }
}
