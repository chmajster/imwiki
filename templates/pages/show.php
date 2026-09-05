<?php
use ImWiki\Security\Csrf;
use ImWiki\Security\Html;
use ImWiki\Support\Url;
$title=$page['title'];
$statusLabels=['draft'=>'Szkic','in_review'=>'W przeglądzie','approved'=>'Zatwierdzona','published'=>'Opublikowana','archived'=>'Zarchiwizowana'];
$restrictionLabels=['inherited'=>'Dziedziczone','specific'=>'Ograniczone','private'=>'Prywatna'];
$commentChildren=[];$commentRoots=[];
foreach($comments as $comment){$parent=(int)($comment['parent_id']??0);if($parent>0)$commentChildren[$parent][]=$comment;else $commentRoots[]=$comment;}
$renderComment=function(array $comment,int $depth=0)use(&$renderComment,$commentChildren,$canComment,$canEdit,$page,$commentReactionCounts,$currentUser):void{
  $id=(int)$comment['id'];$reactions=$commentReactionCounts[$id]??[];$threadStatus=(string)($comment['thread_status']??'open');$canModerate=$canEdit||(int)$comment['user_id']===(int)$currentUser['id']; ?>
  <div class="comment<?= $depth>0?' comment-reply':''?><?= $threadStatus==='resolved'?' resolved':''?>" id="comment-<?=$id?>" style="--comment-depth:<?=min($depth,4)?>">
    <div class="comment-meta"><strong><?=Html::e($comment['author_name']?:$comment['author_username'])?></strong><small><?=Html::e($comment['created_at'])?></small><?php if($depth===0&&$threadStatus==='resolved'):?><span class="badge neutral">Rozwiązany</span><?php endif;?></div>
    <p><?=nl2br(Html::e($comment['body']))?></p>
    <div class="reaction-row" aria-label="Reakcje komentarza">
      <?php foreach(['like'=>'Like','thanks'=>'Thanks','confirm'=>'Confirm'] as $key=>$label):$meta=$reactions[$key]??['count'=>0,'mine'=>false];?>
        <form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/comments/'.$id.'/reactions'))?>" class="inline"><?=Csrf::field()?><input type="hidden" name="reaction" value="<?=Html::e($key)?>"><button class="reaction-button<?=$meta['mine']?' active':''?>"><?=Html::e($label)?> <span><?=Html::e($meta['count'])?></span></button></form>
      <?php endforeach;?>
      <?php if($depth===0&&$canModerate):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/comments/'.$id.'/status'))?>" class="inline"><?=Csrf::field()?><input type="hidden" name="status" value="<?=$threadStatus==='resolved'?'open':'resolved'?>"><button class="link-button"><?=$threadStatus==='resolved'?'Otwórz ponownie':'Rozwiąż wątek'?></button></form><?php endif;?>
    </div>
    <?php if($canComment&&$threadStatus!=='resolved'):?><details class="reply-box"><summary>Odpowiedz</summary><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/comments'))?>"><?=Csrf::field()?><input type="hidden" name="parent_id" value="<?=$id?>"><label>Odpowiedź<textarea name="body" rows="2" maxlength="10000" required placeholder="Możesz użyć @username"></textarea></label><button class="button secondary">Dodaj odpowiedź</button></form></details><?php endif;?>
    <?php foreach($commentChildren[$id]??[] as $child)$renderComment($child,$depth+1);?>
  </div>
<?php };
?>
<nav class="breadcrumbs" aria-label="Okruszki">
  <a href="<?=Html::e(Url::to('/spaces/'.$page['space_key']))?>"><?=Html::e($page['space_name'])?></a>
  <?php foreach($breadcrumbs as $crumb):?><span>›</span><?php if((int)$crumb['id']===(int)$page['id']):?><span aria-current="page"><?=Html::e($crumb['title'])?></span><?php else:?><a href="<?=Html::e(Url::to('/pages/'.$crumb['id']))?>"><?=Html::e($crumb['title'])?></a><?php endif;?><?php endforeach;?>
</nav>
<div class="page-head">
  <div>
    <div class="title-line"><h1><?=Html::e($page['title'])?></h1><span class="badge neutral"><?=Html::e($statusLabels[$page['status']]??$page['status'])?></span><?php if(($page['restriction_mode']??'inherited')!=='inherited'):?><span class="badge warn"><?=Html::e($restrictionLabels[$page['restriction_mode']]??'Ograniczona')?></span><?php endif;?></div>
    <p class="muted">Wersja <?=Html::e($page['version_no'])?> · autor <?=Html::e($page['author_name'])?> · owner @<?=Html::e($page['owner_username']??'—')?> · <?=Html::e($page['updated_at'])?><?php if(!empty($page['review_date'])):?> · przegląd <?=Html::e($page['review_date'])?><?php endif;?></p>
  </div>
  <div class="actions page-actions">
    <?php if($canEdit):?><a class="button secondary" href="<?=Html::e(Url::to('/pages/'.$page['id'].'/edit'))?>">Edytuj</a><?php endif;?>
    <a class="button secondary" href="<?=Html::e(Url::to('/pages/'.$page['id'].'/history'))?>">Historia</a><a class="button secondary" href="<?=Html::e(Url::to('/pages/'.$page['id'].'/export.md'))?>">Markdown</a><a class="button secondary" href="<?=Html::e(Url::to('/pages/'.$page['id'].'/export.html'))?>">HTML</a>
    <?php if($canManageRestrictions):?><a class="button secondary" href="<?=Html::e(Url::to('/pages/'.$page['id'].'/restrictions'))?>">Uprawnienia</a><a class="button secondary" href="<?=Html::e(Url::to('/pages/'.$page['id'].'/public-shares'))?>">Udostępnij</a><?php endif;?><?php if($canEdit):?><a class="button secondary" href="<?=Html::e(Url::to('/pages/'.$page['id'].'/move'))?>">Przenieś / kopiuj</a><?php endif;?><?php if($canManageRestrictions):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/archive'))?>" class="inline"><?=Csrf::field()?><input type="hidden" name="archive" value="<?=$page['status']==='archived'?'0':'1'?>"><button class="button secondary"><?=$page['status']==='archived'?'Przywróć z archiwum':'Archiwizuj'?></button></form><?php endif;?><?php if($canDelete):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/trash'))?>" class="inline" onsubmit="return confirm('Przenieść stronę i jej podstrony do kosza?')"><?=Csrf::field()?><button class="button secondary">Usuń</button></form><?php endif;?>
    <form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/watch'))?>" class="inline"><?=Csrf::field()?><button class="button secondary"><?=$isWatching?'Przestań obserwować':'Obserwuj'?></button></form>
    <form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/favorite'))?>" class="inline"><?=Csrf::field()?><button class="button secondary"><?=$isFavorite?'Usuń z ulubionych':'Dodaj do ulubionych'?></button></form>
  </div>
</div>
<?php if(isset($_GET['upload'])&&$_GET['upload']==='blocked'):?><div class="alert danger">Plik został odrzucony przez reguły bezpieczeństwa.</div><?php elseif(isset($_GET['upload'])&&$_GET['upload']==='error'):?><div class="alert danger">Nie udało się zapisać załącznika.</div><?php endif;?>
<?php if(isset($_GET['task'])&&$_GET['task']==='error'):?><div class="alert danger">Nie udało się utworzyć zadania. Sprawdź opis, użytkownika i termin.</div><?php endif;?>
<article class="wiki-content card" data-page-content><?=$renderedContent?></article>

<section id="reactions" class="page-reactions" aria-label="Reakcje strony">
  <span class="muted">Czy ta strona była pomocna?</span>
  <?php foreach(['helpful'=>'Helpful','like'=>'Like'] as $key=>$label):$meta=$pageReactions[$key]??['count'=>0,'mine'=>false];?>
    <form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/reactions'))?>" class="inline"><?=Csrf::field()?><input type="hidden" name="reaction" value="<?=Html::e($key)?>"><button class="reaction-button<?=$meta['mine']?' active':''?>"><?=Html::e($label)?> <span><?=Html::e($meta['count'])?></span></button></form>
  <?php endforeach;?>
</section>

<section id="backlinks" class="card section-card"><div class="section-heading"><div><p class="eyebrow">Nawigacja</p><h2>Linki do tej strony</h2></div><span class="muted"><?=count($backlinks)?> pozycji</span></div><?php if(!$backlinks):?><div class="empty-state"><p class="muted">Żadna dostępna strona nie zawiera bezpośredniego linku do tej strony.</p></div><?php endif;?><?php foreach($backlinks as $link):?><a class="list-row" href="<?=Html::e(Url::to('/pages/'.$link['id']))?>"><span><strong><?=Html::e($link['title'])?></strong><small><?=Html::e($link['space_name'])?> · <?=Html::e($link['updated_at'])?></small></span></a><?php endforeach;?></section>

<section id="properties" class="card section-card">
  <div class="section-heading"><div><p class="eyebrow">Metadane</p><h2>Właściwości strony</h2></div></div>
  <?php if(!$properties):?><div class="empty-state"><p class="muted">Brak zdefiniowanych właściwości.</p></div><?php else:?><dl class="property-list"><?php foreach($properties as $property):?><div><dt><?=Html::e($property['label'])?></dt><dd><?=Html::e($property['display_value'])?><small><?=Html::e($property['property_type'])?> · <?=Html::e($property['property_key'])?></small></dd><?php if($canEdit):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/properties/'.$property['id'].'/remove'))?>"><?=Csrf::field()?><button class="link-button danger-text">Usuń</button></form><?php endif;?></div><?php endforeach;?></dl><?php endif;?>
  <?php if($canEdit):?><details class="create-panel"><summary>Dodaj lub zmień właściwość</summary><?php if(isset($_GET['property'])):?><div class="alert danger">Nie udało się zapisać właściwości. Sprawdź typ i wartość.</div><?php endif;?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/properties'))?>" class="grid compact-form"><?=Csrf::field()?><label>Klucz<input name="key" pattern="[a-z0-9_.-]{1,100}" maxlength="100" required placeholder="project.status"></label><label>Etykieta<input name="label" maxlength="150" required placeholder="Status projektu"></label><label>Typ<select name="type"><?php foreach($propertyTypes as $type):?><option value="<?=Html::e($type)?>"><?=Html::e($type)?></option><?php endforeach;?></select></label><label>Wartość<input name="value" maxlength="5000" required></label><label>Opcje dla select<input name="options" placeholder="Nowy, W toku, Gotowe"></label><div class="form-action"><button class="button">Zapisz właściwość</button></div></form></details><?php endif;?>
</section>

<?php if($workflowEnabled):?>
<section id="workflow" class="card section-card">
  <div class="section-heading"><div><p class="eyebrow">Publikacja</p><h2>Workflow akceptacji</h2></div><span class="badge neutral"><?=Html::e($statusLabels[$page['status']]??$page['status'])?></span></div>
  <?php if(isset($_GET['workflow'])):?><div class="alert danger">Operacja workflow nie została wykonana. Sprawdź status strony i uprawnienia.</div><?php endif;?>
  <div class="workflow-actions">
    <?php if($canEdit && $page['status']!=='in_review'):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/workflow/draft'))?>"><?=Csrf::field()?><button class="button secondary">Przenieś do szkicu</button></form><?php endif;?>
    <?php if($canEdit && $page['status']==='draft'):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/workflow/request'))?>" class="inline-review-form"><?=Csrf::field()?><label>Reviewer<input name="reviewer" data-user-input autocomplete="off" required placeholder="username"></label><button class="button">Wyślij do przeglądu</button></form><?php endif;?>
    <?php if($canDecideApproval && $page['status']==='in_review'):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/workflow/decision'))?>" class="decision-form"><?=Csrf::field()?><label>Komentarz decyzji<input name="comment" maxlength="1000"></label><button class="button" name="decision" value="approved">Zatwierdź</button><button class="button secondary" name="decision" value="rejected">Odrzuć</button></form><?php endif;?>
    <?php if($canEdit && $page['status']==='approved'):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/workflow/publish'))?>"><?=Csrf::field()?><button class="button">Opublikuj</button></form><?php endif;?>
  </div>
  <?php if($approvalHistory):?><h3>Historia decyzji</h3><?php foreach($approvalHistory as $approval):?><div class="list-row"><div><strong><?=Html::e(ucfirst($approval['decision']))?></strong><small>v<?=Html::e($approval['page_version'])?> · reviewer <?=Html::e($approval['reviewer_username']??'—')?> · zgłosił <?=Html::e($approval['requester_username'])?> · <?=Html::e($approval['created_at'])?><?php if($approval['comment']):?><br><?=Html::e($approval['comment'])?><?php endif;?></small></div></div><?php endforeach;?><?php endif;?>
</section>
<?php endif;?>

<section id="tasks" class="card section-card">
  <div class="section-heading"><div><p class="eyebrow">Praca</p><h2>Zadania</h2></div><span class="muted"><?=count($tasks)?> pozycji</span></div>
  <?php if(!$tasks):?><div class="empty-state"><p class="muted">Ta strona nie ma jeszcze zadań.</p></div><?php endif;?>
  <?php foreach($tasks as $task):?>
    <div class="task-row <?=$task['status']==='done'?'completed':''?>">
      <div><strong><?=Html::e($task['description'])?></strong><p class="muted"><?php if($task['assignee_username']):?>@<?=Html::e($task['assignee_username'])?><?php else:?>bez przypisania<?php endif;?><?php if($task['due_date']):?> · termin <?=Html::e($task['due_date'])?><?php endif;?></p></div>
      <?php $canToggle=$canEdit || (int)($task['assignee_id']??0)===(int)$currentUser['id'] || (int)$task['created_by']===(int)$currentUser['id']; if($canToggle):?><form method="post" action="<?=Html::e(Url::to('/tasks/'.$task['id'].'/complete'))?>"><?=Csrf::field()?><input type="hidden" name="completed" value="<?=$task['status']==='done'?'0':'1'?>"><button class="button secondary"><?=$task['status']==='done'?'Otwórz ponownie':'Wykonane'?></button></form><?php endif;?>
    </div>
  <?php endforeach;?>
  <?php if($canEdit):?><details class="create-panel"><summary>Dodaj zadanie</summary><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/tasks'))?>" class="grid compact-form"><?=Csrf::field()?><label>Opis<input name="description" maxlength="1000" required placeholder="Np. Zweryfikować dokumentację"></label><label>Przypisz do<input name="assignee" data-user-input autocomplete="off" placeholder="username"></label><label>Termin<input type="date" name="due_date"></label><div class="form-action"><button class="button">Dodaj zadanie</button></div></form></details><?php endif;?>
</section>

<section id="attachments" class="card section-card">
  <div class="section-heading"><div><p class="eyebrow">Pliki</p><h2>Załączniki</h2></div></div>
  <?php if(!$attachments):?><div class="empty-state"><p class="muted">Brak załączników.</p></div><?php endif;?>
  <?php foreach($attachments as $a):?><div class="list-row attachment-row"><div><?php if(str_starts_with((string)$a['mime_type'],'image/')):?><button type="button" class="image-thumb" data-lightbox-src="<?=Html::e(Url::to('/attachments/'.$a['id'].'/preview'))?>" data-lightbox-name="<?=Html::e($a['original_name'])?>"><img src="<?=Html::e(Url::to('/attachments/'.$a['id'].'/preview'))?>" alt=""></button><?php endif;?><a href="<?=Html::e(Url::to('/attachments/'.$a['id'].'/download'))?>"><strong><?=Html::e($a['original_name'])?></strong></a><small><?=Html::e(number_format(((int)$a['size_bytes'])/1024,1))?> KB · <?=Html::e($a['mime_type'])?> · wersja <?=Html::e($a['current_version'])?></small></div><a class="button secondary small" href="<?=Html::e(Url::to('/attachments/'.$a['id'].'/versions'))?>">Historia pliku</a></div><?php endforeach;?>
  <?php if($canAttach):?><form method="post" enctype="multipart/form-data" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/attachments'))?>" class="upload-form" data-upload-form><?=Csrf::field()?><label>Dodaj plik<input type="file" name="attachments[]" multiple required></label><button class="button">Wyślij</button><span class="muted" data-upload-state></span></form><?php endif;?>
</section>

<section id="inline-comments" class="card section-card">
  <div class="section-heading"><div><p class="eyebrow">Kontekst</p><h2>Komentarze do fragmentów</h2></div><?php if($canComment):?><button type="button" class="button secondary" data-inline-comment-trigger>Dodaj do zaznaczenia</button><?php endif;?></div>
  <?php if(isset($_GET['inline'])):?><div class="alert danger">Nie udało się dodać komentarza. Zaznacz aktualny fragment treści strony.</div><?php endif;?>
  <?php if($canComment):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/inline-comments'))?>" class="inline-comment-form" data-inline-comment-form hidden><?=Csrf::field()?><input type="hidden" name="before" value=""><input type="hidden" name="after" value=""><label>Zaznaczony fragment<textarea name="quote" rows="2" maxlength="1000" readonly required></textarea></label><label>Komentarz<textarea name="body" rows="3" maxlength="10000" required></textarea></label><div class="actions"><button class="button">Dodaj komentarz</button><button type="button" class="button secondary" data-inline-comment-cancel>Anuluj</button></div></form><?php endif;?>
  <?php if(!$inlineComments):?><div class="empty-state"><p class="muted">Brak komentarzy do fragmentów.</p></div><?php endif;?>
  <?php foreach($inlineComments as $inline):?><article class="inline-comment<?=$inline['status']==='resolved'?' resolved':''?>" id="inline-<?=Html::e($inline['id'])?>"><blockquote><?=Html::e($inline['quote_text'])?></blockquote><div><strong><?=Html::e($inline['author_name']?:$inline['username'])?></strong> <small><?=Html::e($inline['created_at'])?> · v<?=Html::e($inline['page_version'])?></small><?php if($inline['status']==='resolved'):?> <span class="badge neutral">Rozwiązany</span><?php endif;?><p><?=nl2br(Html::e($inline['body']))?></p><?php if($canEdit||(int)$inline['user_id']===(int)$currentUser['id']):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/inline-comments/'.$inline['id'].'/status'))?>"><?=Csrf::field()?><input type="hidden" name="status" value="<?=$inline['status']==='resolved'?'open':'resolved'?>"><button class="link-button"><?=$inline['status']==='resolved'?'Otwórz ponownie':'Rozwiąż'?></button></form><?php endif;?></div></article><?php endforeach;?>
</section>

<section id="comments" class="card section-card">
  <div class="section-heading"><div><p class="eyebrow">Dyskusja</p><h2>Komentarze</h2></div></div>
  <?php if(!$commentRoots):?><div class="empty-state"><p class="muted">Brak komentarzy.</p></div><?php endif;?>
  <?php foreach($commentRoots as $comment)$renderComment($comment);?>
  <?php if($canComment):?><form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/comments'))?>" class="comment-form"><?=Csrf::field()?><label>Dodaj komentarz<textarea name="body" rows="3" maxlength="10000" required placeholder="Możesz użyć @username"></textarea></label><button class="button">Dodaj komentarz</button></form><?php endif;?>
</section>

<dialog class="image-lightbox" data-image-lightbox aria-labelledby="lightbox-title"><div class="lightbox-bar"><strong id="lightbox-title" data-lightbox-title>Podgląd obrazu</strong><div class="actions"><button type="button" class="button secondary" data-lightbox-zoom-out>−</button><button type="button" class="button secondary" data-lightbox-zoom-in>+</button><a class="button secondary" data-lightbox-download href="#">Pobierz</a><button type="button" class="button" data-lightbox-close>Zamknij</button></div></div><div class="lightbox-stage"><img data-lightbox-image alt="Podgląd załącznika"></div></dialog>
