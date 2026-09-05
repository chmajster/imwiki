<?php
declare(strict_types=1);

namespace ImWiki\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo, private readonly string $prefix = '') {}

    public function findByLogin(string $login): ?array
    {
        $sql = "SELECT * FROM `{$this->prefix}users` WHERE deleted_at IS NULL AND (username = :login OR email = :login) LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['login' => $login]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->prefix}users` WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function roles(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT r.name FROM `{$this->prefix}roles` r JOIN `{$this->prefix}user_roles` ur ON ur.role_id=r.id WHERE ur.user_id=?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function permissions(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT DISTINCT p.name FROM `{$this->prefix}permissions` p JOIN `{$this->prefix}role_permissions` rp ON rp.permission_id=p.id JOIN `{$this->prefix}user_roles` ur ON ur.role_id=rp.role_id WHERE ur.user_id=?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function touchLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare("UPDATE `{$this->prefix}users` SET last_login_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=?");
        $stmt->execute([$userId]);
    }

    public function paginate(int $page = 1, int $perPage = 25): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare("SELECT id,username,first_name,last_name,email,status,last_login_at,created_at FROM `{$this->prefix}users` WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
