<?php
use ImWiki\Security\Csrf;
use ImWiki\Security\Html;
use ImWiki\Support\Url;
$title='Uprawnienia · '.$page['title'];
?>
<div class="page-head">
  <div><p class="eyebrow">Uprawnienia strony</p><h1><?=Html::e($page['title'])?></h1><p class="muted">Kontroluj, kto może zobaczyć i edytować tę stronę.</p></div>
  <a class="button secondary" href="<?=Html::e(Url::to('/pages/'.$page['id']))?>">Wróć do strony</a>
</div>
<?php if(isset($_GET['error'])):?><div class="alert danger">Nie udało się nadać uprawnienia. Sprawdź wybranego użytkownika lub grupę.</div><?php endif;?>
<div class="split restrictions-grid">
  <section class="card">
    <h2>Tryb dostępu</h2>
    <form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/restrictions/mode'))?>">
      <?=Csrf::field()?>
      <label>Kto może zobaczyć tę stronę?
        <select name="mode">
          <option value="inherited" <?=$page['restriction_mode']==='inherited'?'selected':''?>>Dziedziczone z przestrzeni</option>
          <option value="specific" <?=$page['restriction_mode']==='specific'?'selected':''?>>Wybrani użytkownicy i grupy</option>
          <option value="private" <?=$page['restriction_mode']==='private'?'selected':''?>>Prywatna — właściciel i administratorzy</option>
        </select>
      </label>
      <button class="button">Zapisz tryb</button>
    </form>
    <p class="muted">Zmiana na tryb dziedziczony lub prywatny usuwa szczegółowe wpisy ACL tej strony.</p>
  </section>
  <section class="card">
    <h2>Udostępnij</h2>
    <form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/restrictions/grants'))?>" data-restriction-form>
      <?=Csrf::field()?>
      <label>Typ
        <select name="subject_type" id="subject-type">
          <option value="user">Użytkownik</option><option value="group">Grupa</option>
        </select>
      </label>
      <label data-subject-wrap="user">Użytkownik
        <select name="subject_id" data-subject="user">
          <?php foreach($usersList as $u):?><option value="<?=Html::e($u['id'])?>"><?=Html::e($u['username'].' — '.trim($u['first_name'].' '.$u['last_name']))?></option><?php endforeach;?>
        </select>
      </label>
      <label data-subject-wrap="group" hidden>Grupa
        <select data-name="subject_id" data-subject="group" disabled>
          <?php foreach($groupsList as $g):?><option value="<?=Html::e($g['id'])?>"><?=Html::e($g['label'].' — '.$g['name'])?></option><?php endforeach;?>
        </select>
      </label>
      <label>Dostęp<select name="access"><option value="view">Odczyt</option><option value="edit">Edycja</option></select></label>
      <button class="button">Nadaj dostęp</button>
    </form>
  </section>
</div>
<section class="card">
  <h2>Aktualne wpisy ACL</h2>
  <?php if(!$grants):?><div class="empty-state"><p class="muted">Brak szczegółowych wpisów. Strona korzysta z wybranego trybu dostępu.</p></div><?php endif;?>
  <?php foreach($grants as $grant):?>
    <div class="list-row">
      <div><strong><?=Html::e($grant['subject_label']?:$grant['subject_key'])?></strong><small><?=Html::e($grant['subject_type']==='user'?'Użytkownik':'Grupa')?> · <?=((int)$grant['can_edit']===1)?'edycja':'odczyt'?></small></div>
      <form method="post" action="<?=Html::e(Url::to('/pages/'.$page['id'].'/restrictions/grants/'.$grant['id'].'/revoke'))?>"><?=Csrf::field()?><button class="link-button danger-text">Odbierz</button></form>
    </div>
  <?php endforeach;?>
</section>
