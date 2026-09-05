<?php
declare(strict_types=1);

namespace ImWiki\Services;

use PDO;

final class MentionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly NotificationService $notifications,
    ) {}

    public function process(string $text, int $actorId, int $pageId, string $targetType, int $targetId, string $contextKey, string $url): array
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/(?<![\w@])@([A-Za-z0-9._-]{2,100})/u', $plain, $matches);
        $usernames = array_values(array_unique(array_map('mb_strtolower', $matches[1] ?? [])));
        if (!$usernames) return [];

        $placeholders = implode(',', array_fill(0,count($usernames),'?'));
        $stmt = $this->pdo->prepare("SELECT id,username FROM `{$this->prefix}users` WHERE status='active' AND deleted_at IS NULL AND LOWER(username) IN ({$placeholders})");
        $stmt->execute($usernames);
        $mentioned = [];

        foreach ($stmt->fetchAll() as $user) {
            $userId = (int)$user['id'];
            if ($userId === $actorId) continue;
            $insert = $this->pdo->prepare("INSERT IGNORE INTO `{$this->prefix}mentions` (user_id,actor_id,page_id,target_type,target_id,context_key,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())");
            $insert->execute([$userId,$actorId,$pageId,$targetType,$targetId,mb_substr($contextKey,0,120)]);
            if ($insert->rowCount() === 0) continue;
            $this->notifications->create($userId,'mention',$actorId,'page',$pageId,$url,['username'=>$user['username'],'source'=>$targetType]);
            $mentioned[] = $userId;
        }
        return $mentioned;
    }
}
