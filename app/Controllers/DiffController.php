<?php
declare(strict_types=1);

namespace ImWiki\Controllers;

use ImWiki\Http\Request;
use ImWiki\Repositories\PageRepository;
use ImWiki\Repositories\UserRepository;
use ImWiki\Security\Authorization;
use ImWiki\Services\DiffService;
use ImWiki\Services\NotificationService;
use ImWiki\View\View;
use PDO;

final class DiffController extends BaseController
{
    public function __construct(PDO $pdo,string $prefix,View $view,UserRepository $users,Authorization $authz,NotificationService $notifications,private readonly PageRepository $pages,private readonly DiffService $diff)
    {parent::__construct($pdo,$prefix,$view,$users,$authz,$notifications);}

    public function compare(Request $request,array $params): void
    {
        $uid=$this->requireAuth();$page=$this->pages->find((int)$params['id']);if(!$page||!$this->authz->canViewPage($uid,$page)){http_response_code(403);echo $this->view->render('errors/403.php',$this->common());return;}
        $from=max(1,(int)$request->input('from',max(1,(int)$page['version_no']-1)));$to=max(1,(int)$request->input('to',(int)$page['version_no']));
        $old=$this->pages->version((int)$page['id'],$from);$new=$this->pages->version((int)$page['id'],$to);if(!$old||!$new){http_response_code(404);echo $this->view->render('errors/404.php',$this->common());return;}
        $oldProps=$this->normalizeProperties((string)($old['properties_json']??'[]'));$newProps=$this->normalizeProperties((string)($new['properties_json']??'[]'));$propertyChanges=[];foreach(array_unique(array_merge(array_keys($oldProps),array_keys($newProps))) as $key){$a=$oldProps[$key]??null;$b=$newProps[$key]??null;if($a!==$b)$propertyChanges[]=['key'=>$key,'old'=>$a,'new'=>$b];}
        echo $this->view->render('pages/diff.php',$this->common(['page'=>$page,'old'=>$old,'new'=>$new,'diff'=>$this->diff->lineDiff((string)$old['content'],(string)$new['content']),'propertyChanges'=>$propertyChanges]));
    }

    private function normalizeProperties(string $json): array
    {
        $rows=json_decode($json,true);if(!is_array($rows))return[];$out=[];foreach($rows as $r){if(!is_array($r)||empty($r['property_key']))continue;$value=match((string)($r['property_type']??'')){'number'=>(string)($r['value_number']??''),'date'=>(string)($r['value_date']??''),'boolean'=>(string)((int)($r['value_boolean']??0)),'user'=>(string)($r['value_user_id']??''),default=>(string)($r['value_text']??'')};$out[(string)$r['property_key']]=['label'=>(string)($r['label']??$r['property_key']),'type'=>(string)($r['property_type']??'text'),'value'=>$value];}ksort($out);return$out;
    }
}
