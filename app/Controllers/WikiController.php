<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Http\Response;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\SpaceRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\AttachmentService;
use ImWiki\Services\MentionService;
use ImWiki\Services\InteractionService;
use ImWiki\Services\NotificationService;
use ImWiki\Services\PageService;
use ImWiki\Services\PagePropertyService;
use ImWiki\Services\ContentHealthService;
use ImWiki\Services\ContentRenderer;
use ImWiki\Services\TaskService;
use ImWiki\Services\TemplateService;
use ImWiki\Services\WorkflowService;
use ImWiki\Support\EventDispatcher;
use ImWiki\Support\Url;
use ImWiki\View\View;
use PDO;

final class WikiController extends BaseController
{
    public function __construct(
        PDO $pdo,
        string $prefix,
        View $view,
        UserRepository $users,
        Authorization $authz,
        NotificationService $notifications,
        private readonly SpaceRepository $spaces,
        private readonly PageRepository $pages,
        private readonly PageService $pageService,
        private readonly TaskService $tasks,
        private readonly AttachmentService $attachments,
        private readonly MentionService $mentions,
        private readonly EventDispatcher $events,
        private readonly WorkflowService $workflow,
        private readonly PagePropertyService $properties,
        private readonly InteractionService $interactions,
        private readonly ContentHealthService $contentHealth,
        private readonly TemplateService $templates,
        private readonly ContentRenderer $renderer,
    ) { parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications); }

    public function dashboard(Request $request): void
    {
        $uid=$this->requireAuth();$widgets=$this->dashboardWidgets($uid);
        $data=['widgets'=>$widgets,'spaces'=>[],'recent'=>[],'favorites'=>[],'tasks'=>[],'watched'=>[],'drafts'=>[],'activity'=>[]];
        if(in_array('my_spaces',$widgets,true))$data['spaces']=$this->spaces->allVisible($uid,$this->authz->isAdmin($uid));
        if(in_array('recent',$widgets,true)){foreach($this->pages->recent(40) as $candidate){$full=$this->pages->find((int)$candidate['id']);if($full&&$this->authz->canViewPage($uid,$full)){$data['recent'][]=$candidate;if(count($data['recent'])>=10)break;}}}
        if(in_array('favorites',$widgets,true)){$fav=$this->pdo->prepare("SELECT p.id,p.title,s.name space_name FROM `{$this->prefix}favorites` f JOIN `{$this->prefix}pages` p ON p.id=f.page_id JOIN `{$this->prefix}spaces` s ON s.id=p.space_id WHERE f.user_id=? AND p.deleted_at IS NULL ORDER BY f.created_at DESC LIMIT 30");$fav->execute([$uid]);foreach($fav->fetchAll() as $candidate){$full=$this->pages->find((int)$candidate['id']);if($full&&$this->authz->canViewPage($uid,$full)){$data['favorites'][]=$candidate;if(count($data['favorites'])>=10)break;}}}
        if(in_array('tasks',$widgets,true)){$data['tasks']=array_slice($this->tasks->mine($uid,'open',null),0,10);}
        if(in_array('drafts',$widgets,true)){$q=$this->pdo->prepare("SELECT d.id,d.page_id,d.title,d.updated_at,p.title page_title,s.name space_name FROM `{$this->prefix}drafts` d JOIN `{$this->prefix}pages` p ON p.id=d.page_id JOIN `{$this->prefix}spaces` s ON s.id=p.space_id WHERE d.user_id=? AND p.deleted_at IS NULL ORDER BY d.updated_at DESC LIMIT 20");$q->execute([$uid]);foreach($q->fetchAll() as $row){$page=$this->pages->find((int)$row['page_id']);if($page&&$this->authz->canEditPage($uid,$page)){$data['drafts'][]=$row;if(count($data['drafts'])>=10)break;}}}
        if(in_array('watched',$widgets,true)){$q=$this->pdo->prepare("SELECT w.resource_type,w.resource_id,w.created_at FROM `{$this->prefix}watchers` w WHERE w.user_id=? ORDER BY w.created_at DESC LIMIT 30");$q->execute([$uid]);foreach($q->fetchAll() as $w){if($w['resource_type']==='page'){$page=$this->pages->find((int)$w['resource_id']);if($page&&$this->authz->canViewPage($uid,$page))$data['watched'][]=['label'=>$page['title'],'url'=>'/pages/'.$page['id'],'type'=>'Strona'];}else{$space=$this->spaces->find((int)$w['resource_id']);if($space&&$this->authz->canViewSpace($uid,(int)$space['id']))$data['watched'][]=['label'=>$space['name'],'url'=>'/spaces/'.$space['space_key'],'type'=>'Przestrzeń'];}if(count($data['watched'])>=10)break;}}
        if(in_array('activity',$widgets,true)){$q=$this->pdo->query("SELECT a.* FROM `{$this->prefix}activity_log` a ORDER BY a.created_at DESC LIMIT 100");foreach($q->fetchAll() as $a){if($a['resource_type']==='page'&&$a['resource_id']){$page=$this->pages->find((int)$a['resource_id']);if(!$page||!$this->authz->canViewPage($uid,$page))continue;}$data['activity'][]=$a;if(count($data['activity'])>=10)break;}}
        echo $this->view->render('dashboard.php',$this->common($data));
    }

    public function dashboardSettings(Request $request): void
    {
        $uid=$this->requireAuth();echo $this->view->render('profile/dashboard.php',$this->common(['widgets'=>$this->dashboardWidgets($uid),'options'=>$this->dashboardWidgetOptions()]));
    }

    public function saveDashboardSettings(Request $request): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$allowed=array_keys($this->dashboardWidgetOptions());$selected=array_values(array_unique(array_filter((array)$request->input('widgets',[]),static fn($v)=>in_array($v,$allowed,true))));if(!$selected)$selected=['recent','my_spaces'];$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}user_preferences` (user_id,dashboard_json,updated_at) VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE dashboard_json=VALUES(dashboard_json),updated_at=UTC_TIMESTAMP()");$stmt->execute([$uid,json_encode($selected,JSON_UNESCAPED_UNICODE)]);Response::redirect(Url::to('/profile/dashboard?saved=1'));
    }

    public function recentViewed(Request $request): void
    {
        $uid=$this->requireAuth();$stmt=$this->pdo->prepare("SELECT p.id,p.title,s.name space_name,MAX(v.viewed_at) viewed_at FROM `{$this->prefix}page_views` v JOIN `{$this->prefix}pages` p ON p.id=v.page_id JOIN `{$this->prefix}spaces` s ON s.id=p.space_id WHERE v.user_id=? AND p.deleted_at IS NULL GROUP BY p.id,p.title,s.name ORDER BY viewed_at DESC LIMIT 100");$stmt->execute([$uid]);$items=[];foreach($stmt->fetchAll() as $row){$page=$this->pages->find((int)$row['id']);if($page&&$this->authz->canViewPage($uid,$page)){$items[]=$row;if(count($items)>=50)break;}}echo $this->view->render('recent.php',$this->common(['items'=>$items]));
    }

    public function clearRecent(Request $request): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$this->pdo->prepare("DELETE FROM `{$this->prefix}page_views` WHERE user_id=?")->execute([$uid]);$this->audit($request,'history.cleared','user',$uid,'Wyczyszczono własną historię odwiedzin');Response::redirect(Url::to('/recent'));
    }

    public function drafts(Request $request): void
    {
        $uid=$this->requireAuth();$stmt=$this->pdo->prepare("SELECT d.*,p.title page_title,p.space_id,s.name space_name FROM `{$this->prefix}drafts` d JOIN `{$this->prefix}pages` p ON p.id=d.page_id JOIN `{$this->prefix}spaces` s ON s.id=p.space_id WHERE d.user_id=? AND p.deleted_at IS NULL ORDER BY d.updated_at DESC LIMIT 100");$stmt->execute([$uid]);$drafts=[];foreach($stmt->fetchAll() as $row){$page=$this->pages->find((int)$row['page_id']);if($page&&$this->authz->canEditPage($uid,$page)){$drafts[]=$row;if(count($drafts)>=50)break;}}echo $this->view->render('drafts.php',$this->common(['drafts'=>$drafts]));
    }

    public function deleteDraft(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$stmt=$this->pdo->prepare("DELETE FROM `{$this->prefix}drafts` WHERE id=? AND user_id=?");$stmt->execute([(int)$params['id'],$uid]);Response::redirect(Url::to('/drafts'));
    }

    public function spaces(Request $request): void
    {
        $uid=$this->requireAuth();
        $spaces=$this->spaces->allVisible($uid,$this->authz->isAdmin($uid));
        $fav=$this->pdo->prepare("SELECT space_id FROM `{$this->prefix}favorite_spaces` WHERE user_id=?");$fav->execute([$uid]);$favoriteIds=array_map('intval',$fav->fetchAll(PDO::FETCH_COLUMN));
        usort($spaces,static function(array $a,array $b)use($favoriteIds):int{$af=in_array((int)$a['id'],$favoriteIds,true);$bf=in_array((int)$b['id'],$favoriteIds,true);return $af===$bf?strnatcasecmp((string)$a['name'],(string)$b['name']):($af?-1:1);});
        echo $this->view->render('spaces/index.php',$this->common(['spaces'=>$spaces,'canCreate'=>$this->authz->can($uid,'spaces.create'),'favoriteSpaceIds'=>$favoriteIds]));
    }

    public function createSpace(Request $request): void
    {
        $uid=$this->requireAuth();$this->csrf($request);
        if(!$this->authz->can($uid,'spaces.create')){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        $name=trim((string)$request->input('name'));$key=strtoupper(trim((string)$request->input('key')));$desc=trim((string)$request->input('description'));
        if($name===''||!preg_match('/^[A-Z][A-Z0-9_-]{1,19}$/',$key))Response::redirect(Url::to('/spaces?error=invalid'));
        try{$id=$this->spaces->create($name,$key,$desc,$uid);$this->audit($request,'space.created','space',$id,'Utworzono Space '.$key);Response::redirect(Url::to('/spaces/'.$key));}catch(\Throwable){Response::redirect(Url::to('/spaces?error=create'));}
    }

    public function space(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$space=$this->spaces->findByKey($params['key']);
        if(!$space){http_response_code(404);echo $this->view->render('errors/404.php',$this->common());return;}
        if(!$this->authz->canViewSpace($uid,(int)$space['id'])){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        $homepageId=(int)($space['homepage_page_id']??0);if($homepageId>0&&(string)$request->input('overview','')!=='1'){$homepage=$this->pages->find($homepageId);if($homepage&&$this->authz->canViewPage($uid,$homepage))Response::redirect(Url::to('/pages/'.$homepageId));}
        $fav=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}favorite_spaces` WHERE user_id=? AND space_id=?");$fav->execute([$uid,(int)$space['id']]);
        $watch=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}watchers` WHERE user_id=? AND resource_type='space' AND resource_id=?");$watch->execute([$uid,(int)$space['id']]);
        $tree=$this->pages->treeVisible((int)$space['id'],$uid,$this->authz->isAdmin($uid));$ids=array_map(static fn(array $p)=>(int)$p['id'],$tree);$popular=[];$contributors=[];if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$q=$this->pdo->prepare("SELECT p.id,p.title,COUNT(v.id) views FROM `{$this->prefix}pages` p LEFT JOIN `{$this->prefix}page_views` v ON v.page_id=p.id WHERE p.id IN ({$ph}) GROUP BY p.id,p.title ORDER BY views DESC,p.title LIMIT 5");$q->execute($ids);$popular=$q->fetchAll();$q=$this->pdo->prepare("SELECT u.username,CONCAT(u.first_name,' ',u.last_name) name,COUNT(*) edits FROM `{$this->prefix}page_versions` pv JOIN `{$this->prefix}users` u ON u.id=pv.author_id WHERE pv.page_id IN ({$ph}) GROUP BY u.id,u.username,u.first_name,u.last_name ORDER BY edits DESC LIMIT 5");$q->execute($ids);$contributors=$q->fetchAll();}$sidebar=$space['sidebar_config_json']?json_decode((string)$space['sidebar_config_json'],true)?:[]:[];echo $this->view->render('spaces/show.php',$this->common(['space'=>$space,'tree'=>$tree,'children'=>$this->pages->childrenVisible((int)$space['id'],null,$uid,$this->authz->isAdmin($uid)),'isFavorite'=>(bool)$fav->fetchColumn(),'isWatching'=>(bool)$watch->fetchColumn(),'canCreate'=>$this->authz->canCreatePage($uid,(int)$space['id']),'canManage'=>$this->authz->canManageSpace($uid,(int)$space['id']),'pageCount'=>count($tree),'popularPages'=>$popular,'contributors'=>$contributors,'sidebarLinks'=>$sidebar]));
    }

    public function newPage(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$space=$this->spaces->findByKey($params['key']);
        if(!$space||!$this->authz->canCreatePage($uid,(int)$space['id'])){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        echo $this->view->render('pages/form.php',$this->common(['page'=>null,'space'=>$space,'tree'=>$this->pages->treeVisible((int)$space['id'],$uid,$this->authz->isAdmin($uid)),'templatesList'=>$this->templates->available($uid,(int)$space['id']),'selectedTemplate'=>(int)$request->input('template_id',0),'error'=>'']));
    }

    public function createPage(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$space=$this->spaces->findByKey($params['key']);
        if(!$space||!$this->authz->canCreatePage($uid,(int)$space['id'])){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        try{$parent=(int)$request->input('parent_id',0);$templateId=(int)$request->input('template_id',0);$content=(string)$request->input('content');if($templateId>0&&trim($content)===''){$template=$this->templates->find($templateId,$uid);if(!$template||$template['archived_at']!==null||($template['space_id']!==null&&(int)$template['space_id']!==(int)$space['id']))throw new \InvalidArgumentException('Wybrany szablon nie jest dostępny.');$content=(string)$template['content'];}$id=$this->pageService->create((int)$space['id'],$parent>0?$parent:null,(string)$request->input('title'),$content,$uid);if($templateId>0)$this->templates->applyToPage($templateId,$id,$uid);$this->audit($request,'page.created','page',$id,'Utworzono stronę');Response::redirect(Url::to('/pages/'.$id));}
        catch(\Throwable $e){echo $this->view->render('pages/form.php',$this->common(['page'=>null,'space'=>$space,'tree'=>$this->pages->treeVisible((int)$space['id'],$uid,$this->authz->isAdmin($uid)),'templatesList'=>$this->templates->available($uid,(int)$space['id']),'selectedTemplate'=>(int)$request->input('template_id',0),'error'=>$e instanceof \InvalidArgumentException?$e->getMessage():'Nie udało się utworzyć strony.']));}
    }

    public function page(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$page=$this->pages->find((int)$params['id']);
        if(!$page){http_response_code(404);echo $this->view->render('errors/404.php',$this->common());return;}
        if(!$this->authz->canViewPage($uid,$page)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        $view=$this->pdo->prepare("INSERT INTO `{$this->prefix}page_views` (user_id,page_id,viewed_at) VALUES (?,?,UTC_TIMESTAMP())");$view->execute([$uid,(int)$page['id']]);
        $comments=$this->pdo->prepare("SELECT c.*,CONCAT(u.first_name,' ',u.last_name) author_name,u.username author_username FROM `{$this->prefix}comments` c JOIN `{$this->prefix}users` u ON u.id=c.user_id WHERE c.page_id=? AND c.deleted_at IS NULL ORDER BY c.created_at,c.id LIMIT 200");$comments->execute([(int)$page['id']]);
        $fav=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}favorites` WHERE user_id=? AND page_id=?");$fav->execute([$uid,(int)$page['id']]);
        $watch=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}watchers` WHERE user_id=? AND resource_type='page' AND resource_id=?");$watch->execute([$uid,(int)$page['id']]);
        $breadcrumbs=[];
        foreach($this->pages->breadcrumbs((int)$page['id']) as $crumb){
            $crumbPage=$this->pages->find((int)$crumb['id']);
            if($crumbPage && $this->authz->canViewPage($uid,$crumbPage)) $breadcrumbs[]=$crumb;
        }
        echo $this->view->render('pages/show.php',$this->common([
            'page'=>$page,'renderedContent'=>$this->renderer->render($page,$uid),'breadcrumbs'=>$breadcrumbs,'comments'=>$comments->fetchAll(),'attachments'=>$this->attachments->currentForPage((int)$page['id']),'tasks'=>$this->tasks->forPage((int)$page['id']),
            'isFavorite'=>(bool)$fav->fetchColumn(),'isWatching'=>(bool)$watch->fetchColumn(),'canEdit'=>$this->authz->canEditPage($uid,$page),'canComment'=>$this->authz->canCommentPage($uid,$page),'canAttach'=>$this->authz->canAttachPage($uid,$page),'canManageRestrictions'=>$this->authz->canManagePageRestrictions($uid,$page),'canDelete'=>$this->authz->canDeletePage($uid,$page),
            'workflowEnabled'=>$this->workflow->enabled(),'approvalHistory'=>$this->workflow->history((int)$page['id']),'canDecideApproval'=>$this->workflow->canDecide((int)$page['id'],$uid),'properties'=>$this->properties->forPage((int)$page['id']),'propertyTypes'=>PagePropertyService::TYPES,'pageReactions'=>$this->interactions->pageReactionCounts((int)$page['id'],$uid),'commentReactionCounts'=>$this->interactions->commentReactionCounts((int)$page['id'],$uid),'inlineComments'=>$this->interactions->inlineForPage((int)$page['id']),'backlinks'=>$this->contentHealth->backlinks((int)$page['id'])
        ]));
    }


    public function friendlyPage(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$space=$this->spaces->findByKey((string)$params['key']);if(!$space||!$this->authz->canViewSpace($uid,(int)$space['id'])){http_response_code(404);echo $this->view->render('errors/404.php',$this->common());return;}
        $slug=(string)$params['slug'];$page=$this->pages->findBySlug((int)$space['id'],$slug);$redirected=false;if(!$page){$page=$this->pages->redirectedPage((int)$space['id'],$slug);$redirected=$page!==null;}
        if(!$page||!$this->authz->canViewPage($uid,$page)){http_response_code(404);echo $this->view->render('errors/404.php',$this->common());return;}
        if($redirected){header('Location: '.Url::to('/spaces/'.$page['space_key'].'/pages/'.$page['slug']),true,301);exit;}
        Response::redirect(Url::to('/pages/'.$page['id']));
    }

    public function editPage(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$page=$this->pages->find((int)$params['id']);
        if(!$page||!$this->authz->canEditPage($uid,$page)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        $space=$this->spaces->findByKey($page['space_key']);$draftId=(int)$request->input('draft',0);if($draftId>0){$d=$this->pdo->prepare("SELECT title,content,base_version FROM `{$this->prefix}drafts` WHERE id=? AND user_id=? AND page_id=?");$d->execute([$draftId,$uid,(int)$page['id']]);$draft=$d->fetch();if($draft){$page['title']=$draft['title'];$page['content']=$draft['content'];$page['version_no']=$draft['base_version'];}}
        echo $this->view->render('pages/form.php',$this->common(['page'=>$page,'space'=>$space,'tree'=>$this->pages->treeVisible((int)$page['space_id'],$uid,$this->authz->isAdmin($uid)),'error'=>'']));
    }

    public function updatePage(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$page=$this->pages->find((int)$params['id']);
        if(!$page||!$this->authz->canEditPage($uid,$page)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        try{$parent=(int)$request->input('parent_id',0);$this->pageService->update((int)$page['id'],(string)$request->input('title'),(string)$request->input('content'),(int)$request->input('base_version'),$parent>0?$parent:null,$uid,(string)$request->input('change_comment'));$reviewDate=trim((string)$request->input('review_date',''));if($reviewDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$reviewDate))throw new \InvalidArgumentException('Nieprawidłowa data przeglądu.');$ownerId=(int)($page['owner_id']??$page['author_id']);if($this->authz->canManagePageRestrictions($uid,$page)){ $owner=trim((string)$request->input('owner_username',''));if($owner!==''){$q=$this->pdo->prepare("SELECT id FROM `{$this->prefix}users` WHERE username=? AND status='active' AND deleted_at IS NULL");$q->execute([$owner]);$ownerId=(int)($q->fetchColumn()?:0);if($ownerId<=0)throw new \InvalidArgumentException('Nie znaleziono właściciela.');}}$this->pdo->prepare("UPDATE `{$this->prefix}pages` SET owner_id=?,review_date=? WHERE id=?")->execute([$ownerId,$reviewDate!==''?$reviewDate:null,(int)$page['id']]);$this->audit($request,'page.updated','page',(int)$page['id'],'Zaktualizowano stronę');Response::redirect(Url::to('/pages/'.$page['id']));}
        catch(\RuntimeException $e){$error=$e->getMessage()==='CONFLICT'?'Ta strona została zmodyfikowana przez innego użytkownika. Otwórz najnowszą wersję i użyj porównania wersji.':'Nie udało się zapisać strony.';$space=$this->spaces->findByKey($page['space_key']);echo $this->view->render('pages/form.php',$this->common(['page'=>$page,'space'=>$space,'tree'=>$this->pages->treeVisible((int)$page['space_id'],$uid,$this->authz->isAdmin($uid)),'error'=>$error]));}
        catch(\InvalidArgumentException $e){$space=$this->spaces->findByKey($page['space_key']);echo $this->view->render('pages/form.php',$this->common(['page'=>$page,'space'=>$space,'tree'=>$this->pages->treeVisible((int)$page['space_id'],$uid,$this->authz->isAdmin($uid)),'error'=>$e->getMessage()]));}
    }

    public function history(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canViewPage($uid,$page)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        echo $this->view->render('pages/history.php',$this->common(['page'=>$page,'versions'=>$this->pages->history((int)$page['id']),'canEdit'=>$this->authz->canEditPage($uid,$page)]));
    }

    public function restore(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canEditPage($uid,$page)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        $this->pageService->restore((int)$page['id'],(int)$request->input('version'),$uid);$this->audit($request,'page.restored','page',(int)$page['id'],'Przywrócono wersję '.$request->input('version'));Response::redirect(Url::to('/pages/'.$page['id'].'/history'));
    }

    public function search(Request $request): void
    {
        $uid=$this->requireAuth();$q=trim((string)$request->input('q',''));$results=[];foreach($this->pages->search($q,50) as $row){$page=$this->pages->find((int)$row['id']);if($page&&$this->authz->canViewPage($uid,$page))$results[]=$row;}echo $this->view->render('search.php',$this->common(['query'=>$q,'results'=>$results]));
    }

    public function comment(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canCommentPage($uid,$page)){http_response_code(403);return;}
        $body=trim((string)$request->input('body'));if($body===''||mb_strlen($body)>10000)Response::redirect(Url::to('/pages/'.$page['id'].'?comment=invalid#comments'));
        $parent=(int)$request->input('parent_id',0);$parentRow=null;if($parent>0){$check=$this->pdo->prepare("SELECT id,user_id FROM `{$this->prefix}comments` WHERE id=? AND page_id=? AND deleted_at IS NULL");$check->execute([$parent,(int)$page['id']]);$parentRow=$check->fetch();if(!$parentRow)$parent=0;}
        $stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}comments` (page_id,parent_id,user_id,body,created_at,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$stmt->execute([(int)$page['id'],$parent?:null,$uid,$body]);$commentId=(int)$this->pdo->lastInsertId();
        $this->mentions->process($body,$uid,(int)$page['id'],'comment',$commentId,'comment:'.$commentId,'/pages/'.$page['id'].'#comment-'.$commentId);
        if($parentRow&&(int)$parentRow['user_id']!==$uid)$this->notifications?->create((int)$parentRow['user_id'],'comment.reply',$uid,'page',(int)$page['id'],'/pages/'.$page['id'].'#comment-'.$commentId,['comment_id'=>$commentId]);
        $this->events->dispatch('comment.created',['actor_id'=>$uid,'page_id'=>(int)$page['id'],'space_id'=>(int)$page['space_id'],'comment_id'=>$commentId,'url'=>'/pages/'.$page['id'].'#comment-'.$commentId]);
        $this->audit($request,'comment.created','comment',$commentId,'Dodano komentarz');Response::redirect(Url::to('/pages/'.$page['id'].'#comment-'.$commentId));
    }

    public function favorite(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canViewPage($uid,$page)){http_response_code(403);return;}
        $check=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}favorites` WHERE user_id=? AND page_id=?");$check->execute([$uid,(int)$page['id']]);if($check->fetchColumn()){$stmt=$this->pdo->prepare("DELETE FROM `{$this->prefix}favorites` WHERE user_id=? AND page_id=?");$stmt->execute([$uid,(int)$page['id']]);}else{$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}favorites` (user_id,page_id,created_at) VALUES (?,?,UTC_TIMESTAMP())");$stmt->execute([$uid,(int)$page['id']]);}Response::redirect(Url::to('/pages/'.$page['id']));
    }

    public function watchPage(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canViewPage($uid,$page)){http_response_code(403);return;}$this->toggleWatch($uid,'page',(int)$page['id']);Response::redirect(Url::to('/pages/'.$page['id']));
    }

    public function watchSpace(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$space=$this->spaces->findByKey($params['key']);if(!$space||!$this->authz->canViewSpace($uid,(int)$space['id'])){http_response_code(403);return;}$this->toggleWatch($uid,'space',(int)$space['id']);Response::redirect(Url::to('/spaces/'.$space['space_key']));
    }

    public function favoriteSpace(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$this->csrf($request);$space=$this->spaces->findByKey($params['key']);if(!$space||!$this->authz->canViewSpace($uid,(int)$space['id'])){http_response_code(403);return;}
        $check=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}favorite_spaces` WHERE user_id=? AND space_id=?");$check->execute([$uid,(int)$space['id']]);if($check->fetchColumn()){$stmt=$this->pdo->prepare("DELETE FROM `{$this->prefix}favorite_spaces` WHERE user_id=? AND space_id=?");$stmt->execute([$uid,(int)$space['id']]);}else{$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}favorite_spaces` (user_id,space_id,created_at) VALUES (?,?,UTC_TIMESTAMP())");$stmt->execute([$uid,(int)$space['id']]);}Response::redirect(Url::to('/spaces/'.$space['space_key']));
    }

    private function dashboardWidgetOptions(): array { return ['favorites'=>'Ulubione','recent'=>'Ostatnio edytowane','tasks'=>'Zadania','watched'=>'Obserwowane','drafts'=>'Szkice','activity'=>'Aktywność','my_spaces'=>'Moje przestrzenie']; }
    private function dashboardWidgets(int $userId): array {$stmt=$this->pdo->prepare("SELECT dashboard_json FROM `{$this->prefix}user_preferences` WHERE user_id=?");$stmt->execute([$userId]);$v=json_decode((string)($stmt->fetchColumn()?:''),true);$allowed=array_keys($this->dashboardWidgetOptions());if(!is_array($v))return['recent','my_spaces','favorites','tasks'];return array_values(array_filter($v,static fn($x)=>in_array($x,$allowed,true)));}

    private function toggleWatch(int $userId,string $type,int $id): void
    {
        $check=$this->pdo->prepare("SELECT COUNT(*) FROM `{$this->prefix}watchers` WHERE user_id=? AND resource_type=? AND resource_id=?");$check->execute([$userId,$type,$id]);
        if($check->fetchColumn()){$stmt=$this->pdo->prepare("DELETE FROM `{$this->prefix}watchers` WHERE user_id=? AND resource_type=? AND resource_id=?");$stmt->execute([$userId,$type,$id]);}
        else{$stmt=$this->pdo->prepare("INSERT INTO `{$this->prefix}watchers` (user_id,resource_type,resource_id,created_at) VALUES (?,?,?,UTC_TIMESTAMP())");$stmt->execute([$userId,$type,$id]);}
    }
}
