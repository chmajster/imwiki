<?php
declare(strict_types=1);
namespace ImWiki\Services;
use ImWiki\Repositories\PageRepository;use ImWiki\Security\Authorization;use ImWiki\Security\Html;use ImWiki\Support\Url;use PDO;
final class ContentRenderer{
 public function __construct(private readonly PDO $pdo,private readonly string $prefix,private readonly PageRepository $pages,private readonly Authorization $authz){}
 public function render(array $page,int $userId):string{
  $html=(string)$page['content'];$pageId=(int)$page['id'];$spaceId=(int)$page['space_id'];
  $html=$this->replace($html,'toc','<nav class="macro macro-info generated-toc" data-generated-toc><strong>Spis treści</strong></nav>');
  $html=$this->replace($html,'children',$this->children($pageId,$spaceId,$userId));
  $html=$this->replace($html,'recently-updated',$this->recent($spaceId,$userId));
  $html=$this->replace($html,'page-properties',$this->properties($pageId));
  $html=$this->replace($html,'task-list',$this->tasks($pageId));
  $html=preg_replace_callback('/(?:<p>\s*)?\{\{page:(\d+)\}\}(?:\s*<\/p>)?/i',function(array $m)use($userId){$target=$this->pages->find((int)$m[1]);if(!$target||!$this->authz->canViewPage($userId,$target))return '<span class="muted">Niedostępna strona</span>';return '<a href="'.Html::e(Url::to('/pages/'.$target['id'])).'">'.Html::e($target['title']).'</a>';},$html)??$html;
  return $html;
 }
 private function replace(string $html,string $name,string $replacement):string{return preg_replace('/(?:<p>\s*)?\{\{'.preg_quote($name,'/').'\}\}(?:\s*<\/p>)?/i',$replacement,$html)??$html;}
 private function children(int $pageId,int $spaceId,int $uid):string{$items=$this->pages->childrenVisible($spaceId,$pageId,$uid,$this->authz->isAdmin($uid));if(!$items)return'<div class="macro macro-info"><strong>Podstrony</strong><p>Brak podstron.</p></div>';$out='<div class="macro macro-info"><strong>Podstrony</strong><ul>';foreach($items as $p)$out.='<li><a href="'.Html::e(Url::to('/pages/'.$p['id'])).'">'.Html::e($p['title']).'</a></li>';return$out.'</ul></div>';}
 private function recent(int $spaceId,int $uid):string{$tree=$this->pages->treeVisible($spaceId,$uid,$this->authz->isAdmin($uid));$ids=array_slice(array_map(static fn($r)=>(int)$r['id'],$tree),0,500);if(!$ids)return'<div class="macro macro-info"><strong>Ostatnio zmienione</strong><p>Brak stron.</p></div>';$ph=implode(',',array_fill(0,count($ids),'?'));$q=$this->pdo->prepare("SELECT id,title,updated_at FROM `{$this->prefix}pages` WHERE id IN ({$ph}) ORDER BY updated_at DESC LIMIT 10");$q->execute($ids);$out='<div class="macro macro-info"><strong>Ostatnio zmienione</strong><ul>';foreach($q->fetchAll() as $p)$out.='<li><a href="'.Html::e(Url::to('/pages/'.$p['id'])).'">'.Html::e($p['title']).'</a> <small>'.Html::e($p['updated_at']).'</small></li>';return$out.'</ul></div>';}
 private function properties(int $pageId):string{$q=$this->pdo->prepare("SELECT property_key,label,property_type,value_text,value_number,value_date,value_user_id,value_boolean FROM `{$this->prefix}page_properties` WHERE page_id=? ORDER BY label LIMIT 100");$q->execute([$pageId]);$rows=$q->fetchAll();if(!$rows)return'<div class="macro macro-info"><strong>Właściwości</strong><p>Brak właściwości.</p></div>';$out='<div class="macro macro-info"><strong>Właściwości</strong><dl class="system-list">';foreach($rows as $r){$v=match($r['property_type']){'number'=>(string)$r['value_number'],'date'=>(string)$r['value_date'],'boolean'=>(int)$r['value_boolean']===1?'Tak':'Nie','user'=>'Użytkownik #'.(int)$r['value_user_id'],default=>(string)$r['value_text']};$out.='<dt>'.Html::e($r['label']).'</dt><dd>'.Html::e($v).'</dd>';}return$out.'</dl></div>';}
 private function tasks(int $pageId):string{$q=$this->pdo->prepare("SELECT description,status,due_date FROM `{$this->prefix}tasks` WHERE page_id=? ORDER BY status,due_date IS NULL,due_date,created_at LIMIT 100");$q->execute([$pageId]);$rows=$q->fetchAll();if(!$rows)return'<div class="macro macro-info"><strong>Zadania</strong><p>Brak zadań.</p></div>';$out='<div class="macro macro-info"><strong>Zadania</strong><ul>';foreach($rows as $r)$out.='<li>'.($r['status']==='done'?'✓ ':'□ ').Html::e($r['description']).($r['due_date']?' <small>'.Html::e($r['due_date']).'</small>':'').'</li>';return$out.'</ul></div>';}
}
