<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\PageRepository;
use ImWiki\Security\Authorization;
use PDO;

final class TaskService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly PageRepository $pages,
        private readonly Authorization $authz,
        private readonly NotificationService $notifications,
    ) {}

    public function create(int $pageId, string $description, ?string $assigneeUsername, ?string $dueDate, int $creatorId): int
    {
        $page = $this->pages->find($pageId);
        if (!$page || !$this->authz->canEditPage($creatorId,$page)) throw new \RuntimeException('FORBIDDEN');
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 1000) throw new \InvalidArgumentException('Opis zadania jest wymagany i może mieć maksymalnie 1000 znaków.');

        $assigneeId = null;
        if ($assigneeUsername !== null && trim($assigneeUsername) !== '') {
            $stmt = $this->pdo->prepare("SELECT id FROM `{$this->prefix}users` WHERE username=? AND status='active' AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([trim($assigneeUsername)]);
            $assigneeId = (int)($stmt->fetchColumn() ?: 0);
            if ($assigneeId <= 0) throw new \InvalidArgumentException('Nie znaleziono aktywnego użytkownika o podanym loginie.');
        }
        if ($dueDate !== null && $dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dueDate)) throw new \InvalidArgumentException('Nieprawidłowa data terminu.');

        $stmt = $this->pdo->prepare("INSERT INTO `{$this->prefix}tasks` (page_id,description,status,assignee_id,created_by,due_date,created_at,updated_at) VALUES (?,?,'open',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt->execute([$pageId,$description,$assigneeId,$creatorId,$dueDate ?: null]);
        $id = (int)$this->pdo->lastInsertId();
        if ($assigneeId !== null && $assigneeId !== $creatorId) {
            $this->notifications->create($assigneeId,'task.assigned',$creatorId,'task',$id,'/pages/'.$pageId.'#tasks',['description'=>$description]);
        }
        return $id;
    }

    public function setCompleted(int $taskId, bool $completed, int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT t.*,p.space_id,p.author_id,p.owner_id,p.status page_status,p.restriction_mode FROM `{$this->prefix}tasks` t JOIN `{$this->prefix}pages` p ON p.id=t.page_id WHERE t.id=? AND p.deleted_at IS NULL");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) throw new \RuntimeException('NOT_FOUND');
        $page = $this->pages->find((int)$task['page_id']);
        $allowed = (int)($task['assignee_id'] ?? 0)===$userId || (int)$task['created_by']===$userId || ($page && $this->authz->canEditPage($userId,$page));
        if (!$allowed) throw new \RuntimeException('FORBIDDEN');
        $upd = $this->pdo->prepare("UPDATE `{$this->prefix}tasks` SET status=?,completed_at=?,updated_at=UTC_TIMESTAMP() WHERE id=?");
        $upd->execute([$completed?'done':'open',$completed?gmdate('Y-m-d H:i:s'):null,$taskId]);
        return (int)$task['page_id'];
    }

    public function forPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare("SELECT t.*,u.username assignee_username,CONCAT(u.first_name,' ',u.last_name) assignee_name FROM `{$this->prefix}tasks` t LEFT JOIN `{$this->prefix}users` u ON u.id=t.assignee_id WHERE t.page_id=? ORDER BY (t.status='open') DESC,t.due_date IS NULL,t.due_date,t.created_at DESC LIMIT 100");
        $stmt->execute([$pageId]);
        return $stmt->fetchAll();
    }

    public function mine(int $userId, string $filter='open', ?int $spaceId=null): array
    {
        $where=['t.assignee_id=:uid','p.deleted_at IS NULL'];
        if ($filter==='open') $where[]="t.status='open'";
        elseif ($filter==='done') $where[]="t.status='done'";
        elseif ($filter==='overdue') $where[]="t.status='open' AND t.due_date IS NOT NULL AND t.due_date < UTC_DATE()";
        if ($spaceId !== null) $where[]='p.space_id=:space';
        $sql="SELECT t.*,p.title page_title,p.id page_id,s.name space_name,s.id space_id FROM `{$this->prefix}tasks` t JOIN `{$this->prefix}pages` p ON p.id=t.page_id JOIN `{$this->prefix}spaces` s ON s.id=p.space_id WHERE ".implode(' AND ',$where)." ORDER BY t.status,t.due_date IS NULL,t.due_date,t.updated_at DESC LIMIT 200";
        $stmt=$this->pdo->prepare($sql); $params=['uid'=>$userId]; if($spaceId!==null)$params['space']=$spaceId; $stmt->execute($params);
        $rows=[];
        foreach($stmt->fetchAll() as $row){$page=$this->pages->find((int)$row['page_id']);if($page&&$this->authz->canViewPage($userId,$page))$rows[]=$row;}
        return $rows;
    }
}
