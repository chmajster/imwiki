<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;

final class DigestService
{
    public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly NotificationService $notifications,private readonly JobQueueService $jobs){}

    public function opportunistic(int $limit=3): int
    {
        $limit=max(1,min(10,$limit));
        $stmt=$this->pdo->prepare("SELECT up.user_id,up.notification_json FROM `{$this->prefix}user_preferences` up JOIN `{$this->prefix}users` u ON u.id=up.user_id WHERE u.status='active' AND u.deleted_at IS NULL AND up.notification_json IS NOT NULL ORDER BY up.updated_at ASC LIMIT :limit");
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->execute();
        $queued=0;
        foreach($stmt->fetchAll() as $row){
            $prefs=json_decode((string)$row['notification_json'],true)?:[];$frequency=(string)($prefs['email_mode']??'none');
            if(!in_array($frequency,['daily','weekly'],true))continue;
            if($this->enqueueIfDue((int)$row['user_id'],$frequency))$queued++;
        }
        return $queued;
    }

    private function enqueueIfDue(int $userId,string $frequency): bool
    {
        $state=$this->pdo->prepare("SELECT last_sent_at FROM `{$this->prefix}notification_digest_state` WHERE user_id=? AND frequency=?");$state->execute([$userId,$frequency]);$last=(string)($state->fetchColumn()?:'');
        $now=time();$period=$frequency==='daily'?86400:604800;$from=$last!==''?strtotime($last.' UTC'):$now-$period;if($from!==false&&$now-$from<$period)return false;
        $fromSql=gmdate('Y-m-d H:i:s',$from?:$now-$period);$toSql=gmdate('Y-m-d H:i:s',$now);
        if(!$this->notifications->hasDigestItems($userId,$fromSql,$toSql)){$this->touch($userId,$frequency,$toSql);return false;}
        $this->jobs->enqueue('notification_digest',['user_id'=>$userId,'frequency'=>$frequency,'from'=>$fromSql,'to'=>$toSql]);$this->touch($userId,$frequency,$toSql);return true;
    }

    private function touch(int $userId,string $frequency,string $at):void
    {
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}notification_digest_state` (user_id,frequency,last_sent_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_sent_at=VALUES(last_sent_at)");$stmt->execute([$userId,$frequency,$at]);
    }
}
