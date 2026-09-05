<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\InteractionService;
use ImWiki\Services\NotificationService;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;
use Throwable;

final class InteractionController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,?NotificationService $notifications,private readonly InteractionService $interactions){parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}
    public function pageReaction(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$this->interactions->togglePageReaction((int)$p['id'],$uid,(string)$r->input('reaction'));}catch(Throwable){http_response_code(403);return;}Response::redirect(Url::to('/pages/'.$p['id'].'#reactions'));}
    public function commentReaction(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$this->interactions->toggleCommentReaction((int)$p['commentId'],$uid,(string)$r->input('reaction'));}catch(Throwable){http_response_code(403);return;}Response::redirect(Url::to('/pages/'.$p['id'].'#comment-'.$p['commentId']));}
    public function threadStatus(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$pageId=$this->interactions->setThreadStatus((int)$p['commentId'],$uid,(string)$r->input('status'));Response::redirect(Url::to('/pages/'.$pageId.'#comment-'.$p['commentId']));}catch(Throwable){http_response_code(403);}}
    public function inlineCreate(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$id=$this->interactions->createInline((int)$p['id'],$uid,(string)$r->input('quote'),(string)$r->input('body'),(string)$r->input('before'),(string)$r->input('after'));$this->audit($r,'inline_comment.created','inline_comment',$id,'Dodano komentarz do fragmentu');Response::redirect(Url::to('/pages/'.$p['id'].'#inline-'.$id));}catch(Throwable){Response::redirect(Url::to('/pages/'.$p['id'].'?inline=error#inline-comments'));}}
    public function inlineStatus(Request $r,array $p):void{$uid=$this->requireAuth();$this->csrf($r);try{$pageId=$this->interactions->setInlineStatus((int)$p['inlineId'],$uid,(string)$r->input('status'));Response::redirect(Url::to('/pages/'.$pageId.'#inline-'.$p['inlineId']));}catch(Throwable){http_response_code(403);}}
}
