<?php
declare(strict_types=1);

namespace ImWiki\Repositories;

use PDO;

final class SpaceRepository
{
    public function __construct(private readonly PDO $pdo, private readonly string $prefix = '') {}

    public function allVisible(int $userId, bool $admin): array
    {
        if ($admin) {
            return $this->pdo->query("SELECT s.*, CONCAT(u.first_name,' ',u.last_name) owner_name FROM `{$this->prefix}spaces` s JOIN `{$this->prefix}users` u ON u.id=s.owner_id WHERE s.deleted_at IS NULL ORDER BY s.name")->fetchAll();
        }
        $stmt = $this->pdo->prepare("SELECT DISTINCT s.*, CONCAT(u.first_name,' ',u.last_name) owner_name FROM `{$this->prefix}spaces` s JOIN `{$this->prefix}users` u ON u.id=s.owner_id LEFT JOIN `{$this->prefix}space_permissions` sp ON sp.space_id=s.id AND ((sp.subject_type='user' AND sp.subject_id=:uid) OR (sp.subject_type='group' AND sp.subject_id IN (SELECT gu.group_id FROM `{$this->prefix}group_users` gu WHERE gu.user_id=:gid_uid))) WHERE s.deleted_at IS NULL AND (s.visibility='logged_in' OR s.owner_id=:uid2 OR sp.can_view=1) ORDER BY s.name");
        $stmt->execute(['uid' => $userId, 'gid_uid' => $userId, 'uid2' => $userId]);
        return $stmt->fetchAll();
    }

    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->prefix}spaces` WHERE space_key=? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([strtoupper($key)]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $name, string $key, string $description, int $ownerId): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}spaces` (name,space_key,description,owner_id,visibility,created_at,updated_at) VALUES (?,?,?,?, 'logged_in', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $stmt->execute([$name, strtoupper($key), $description, $ownerId]);
        return (int) $this->pdo->lastInsertId();
    }
}
