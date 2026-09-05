<?php
declare(strict_types=1);

namespace ImWiki\Security;

use ImWiki\Repositories\UserRepository;
use PDO;

final class Authorization
{
    public function __construct(private readonly PDO $pdo, private readonly UserRepository $users, private readonly string $prefix='') {}

    public function isAdmin(int $userId): bool
    {
        return in_array('administrator', $this->users->roles($userId), true);
    }

    public function can(int $userId, string $permission): bool
    {
        return $this->isAdmin($userId) || in_array($permission, $this->users->permissions($userId), true);
    }

    public function canViewSpace(int $userId, int $spaceId): bool
    {
        if ($this->isAdmin($userId)) return true;
        $stmt = $this->pdo->prepare("SELECT s.visibility,s.owner_id, MAX(COALESCE(sp.can_view,0)) can_view FROM `{$this->prefix}spaces` s LEFT JOIN `{$this->prefix}space_permissions` sp ON sp.space_id=s.id AND ((sp.subject_type='user' AND sp.subject_id=?) OR (sp.subject_type='group' AND sp.subject_id IN (SELECT gu.group_id FROM `{$this->prefix}group_users` gu WHERE gu.user_id=?))) WHERE s.id=? AND s.deleted_at IS NULL GROUP BY s.id,s.visibility,s.owner_id");
        $stmt->execute([$userId,$userId,$spaceId]);
        $row = $stmt->fetch();
        return (bool)($row && ($row['visibility']==='logged_in' || (int)$row['owner_id']===$userId || (int)$row['can_view']===1));
    }

    public function canManageSpace(int $userId, int $spaceId): bool
    {
        if ($this->isAdmin($userId) || $this->can($userId, 'spaces.manage')) return true;
        $stmt = $this->pdo->prepare("SELECT owner_id FROM `{$this->prefix}spaces` WHERE id=? AND deleted_at IS NULL");
        $stmt->execute([$spaceId]);
        if ((int)$stmt->fetchColumn() === $userId) return true;
        return $this->spacePermission($userId, $spaceId, 'can_manage');
    }

    public function canCreatePage(int $userId, int $spaceId): bool
    {
        if (!$this->canViewSpace($userId, $spaceId)) return false;
        if ($this->spaceArchived($spaceId)) return false;
        if ($this->isAdmin($userId) || $this->can($userId, 'spaces.manage')) return true;
        if ($this->canManageSpace($userId, $spaceId)) return true;
        return $this->spacePermission($userId, $spaceId, 'can_create_page');
    }

    public function canViewPage(int $userId, array $page): bool
    {
        if ($this->isAdmin($userId)) return true;
        $mode = (string)($page['restriction_mode'] ?? 'inherited');
        $ownerId = (int)($page['owner_id'] ?? $page['author_id'] ?? 0);

        if ($mode === 'private') {
            return $ownerId === $userId;
        }
        if ($mode === 'specific') {
            return $ownerId === $userId || $this->pagePermission($userId, (int)$page['id'], 'can_view');
        }

        return $this->canViewSpace($userId, (int)$page['space_id']);
    }

    public function canEditPage(int $userId, array $page): bool
    {
        if (($page['status'] ?? '') === 'archived') return false;
        if ($this->isAdmin($userId) || $this->can($userId, 'spaces.manage')) return true;

        $mode = (string)($page['restriction_mode'] ?? 'inherited');
        $ownerId = (int)($page['owner_id'] ?? $page['author_id'] ?? 0);
        if ($mode === 'private') return $ownerId === $userId;
        if ($mode === 'specific') return $ownerId === $userId || $this->pagePermission($userId, (int)$page['id'], 'can_edit');

        if (!$this->canViewSpace($userId, (int)$page['space_id'])) return false;
        if ($this->canManageSpace($userId, (int)$page['space_id'])) return true;
        return $this->spacePermission($userId, (int)$page['space_id'], 'can_edit_page');
    }

    public function canDeletePage(int $userId, array $page): bool
    {
        if ($this->isAdmin($userId) || $this->can($userId, 'spaces.manage')) return true;
        $mode = (string)($page['restriction_mode'] ?? 'inherited');
        $ownerId = (int)($page['owner_id'] ?? $page['author_id'] ?? 0);
        if ($mode === 'private') return $ownerId === $userId;
        if ($mode === 'specific') return $ownerId === $userId || $this->pagePermission($userId, (int)$page['id'], 'can_delete');
        return $this->canManageSpace($userId, (int)$page['space_id']) || $this->spacePermission($userId, (int)$page['space_id'], 'can_delete_page');
    }

    public function canCommentPage(int $userId, array $page): bool
    {
        if (($page['status'] ?? '') === 'archived') return false;
        if ($this->isAdmin($userId)) return true;
        if (!$this->canViewPage($userId, $page)) return false;

        $mode = (string)($page['restriction_mode'] ?? 'inherited');
        $ownerId = (int)($page['owner_id'] ?? $page['author_id'] ?? 0);
        if ($mode === 'private') return $ownerId === $userId;
        if ($mode === 'specific') return $ownerId === $userId || $this->pagePermission($userId, (int)$page['id'], 'can_comment') || $this->pagePermission($userId, (int)$page['id'], 'can_edit');

        if ($this->canManageSpace($userId, (int)$page['space_id'])) return true;
        return $this->spacePermission($userId, (int)$page['space_id'], 'can_comment');
    }

    public function canAttachPage(int $userId, array $page): bool
    {
        if (($page['status'] ?? '') === 'archived') return false;
        if ($this->isAdmin($userId)) return true;
        if (!$this->canViewPage($userId, $page)) return false;

        $mode = (string)($page['restriction_mode'] ?? 'inherited');
        $ownerId = (int)($page['owner_id'] ?? $page['author_id'] ?? 0);
        if ($mode === 'private') return $ownerId === $userId;
        if ($mode === 'specific') return $ownerId === $userId || $this->pagePermission($userId, (int)$page['id'], 'can_edit');

        if ($this->canManageSpace($userId, (int)$page['space_id'])) return true;
        return $this->spacePermission($userId, (int)$page['space_id'], 'can_attachments') || $this->spacePermission($userId, (int)$page['space_id'], 'can_edit_page');
    }

    public function canManagePageRestrictions(int $userId, array $page): bool
    {
        if ($this->isAdmin($userId) || $this->can($userId, 'spaces.manage')) return true;
        return $this->canManageSpace($userId, (int)$page['space_id']) || (int)($page['owner_id'] ?? $page['author_id'] ?? 0) === $userId;
    }

    private function pagePermission(int $userId, int $pageId, string $column): bool
    {
        $allowed=['can_view','can_edit','can_delete','can_comment'];
        if (!in_array($column,$allowed,true)) return false;
        $stmt=$this->pdo->prepare("SELECT MAX({$column}) FROM `{$this->prefix}page_permissions` WHERE page_id=? AND ((subject_type='user' AND subject_id=?) OR (subject_type='group' AND subject_id IN (SELECT group_id FROM `{$this->prefix}group_users` WHERE user_id=?)))");
        $stmt->execute([$pageId,$userId,$userId]);
        return (int)($stmt->fetchColumn() ?: 0) === 1;
    }

    private function spacePermission(int $userId, int $spaceId, string $column): bool
    {
        $allowed=['can_view','can_create_page','can_edit_page','can_delete_page','can_manage','can_attachments','can_comment'];
        if (!in_array($column,$allowed,true)) return false;
        $stmt=$this->pdo->prepare("SELECT MAX({$column}) FROM `{$this->prefix}space_permissions` WHERE space_id=? AND ((subject_type='user' AND subject_id=?) OR (subject_type='group' AND subject_id IN (SELECT group_id FROM `{$this->prefix}group_users` WHERE user_id=?)))");
        $stmt->execute([$spaceId,$userId,$userId]);
        return (int)($stmt->fetchColumn() ?: 0) === 1;
    }

    private function spaceArchived(int $spaceId): bool
    {
        $stmt = $this->pdo->prepare("SELECT archived_at IS NOT NULL FROM `{$this->prefix}spaces` WHERE id=? AND deleted_at IS NULL");
        $stmt->execute([$spaceId]);
        return (bool)$stmt->fetchColumn();
    }
}
