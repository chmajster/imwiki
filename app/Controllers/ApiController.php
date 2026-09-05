<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Security\Csrf;
use ImWiki\Security\Html;
use ImWiki\Security\RateLimiter;
use ImWiki\View\View;
use PDO;

final class ApiController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,private readonly PageRepository $pages,private readonly RateLimiter $limiter)
    {parent::__construct($pdo,$prefix,$view,$users,$authz);}

    public function autosave(Request $request,array $params): void
    {
        $uid=$this->requireAuth();
        if(!Csrf::validate((string)$request->input('_csrf','')))Response::json(['ok'=>false,'error'=>'csrf'],419);
        $page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canEditPage($uid,$page))Response::json(['ok'=>false,'error'=>'forbidden'],403);
        $title=trim((string)$request->input('title'));$content=Html::sanitizeRichText((string)$request->input('content'));$base=(int)$request->input('base_version');
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}drafts` (page_id,user_id,title,content,base_version,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE title=VALUES(title),content=VALUES(content),base_version=VALUES(base_version),updated_at=VALUES(updated_at)");
        $stmt->execute([(int)$page['id'],$uid,$title,$content,$base]);Response::json(['ok'=>true,'saved_at'=>gmdate('c')]);
    }

    public function users(Request $request): void
    {
        $uid=$this->requireAuth();
        if($this->limiter->tooManyAttempts('users-autocomplete:'.$uid,60,60))Response::json(['ok'=>false,'error'=>'rate_limited'],429);
        $q=trim((string)$request->input('q',''));if(mb_strlen($q)<1)Response::json(['ok'=>true,'items'=>[]]);
        $like=$q.'%';$stmt=$this->pdo->prepare("SELECT id,username,first_name,last_name FROM `{$this->prefix}users` WHERE status='active' AND deleted_at IS NULL AND (username LIKE ? OR first_name LIKE ? OR last_name LIKE ?) ORDER BY CASE WHEN username=? THEN 0 ELSE 1 END,username LIMIT 10");$stmt->execute([$like,$like,$like,$q]);
        $items=array_map(static fn(array $u):array=>['id'=>(int)$u['id'],'username'=>$u['username'],'label'=>trim($u['first_name'].' '.$u['last_name'])?:$u['username']],$stmt->fetchAll());
        Response::json(['ok'=>true,'items'=>$items]);
    }
}
