<?php
use ImWiki\Security\Csrf;
use ImWiki\Security\Html;
use ImWiki\Support\Url;
$title=$title??'imWiki';$notificationCount=(int)($notificationCount??0);$isAdmin=$currentUser&&$authz->can((int)$currentUser['id'],'administration.access');
?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=Html::e($title)?> · imWiki</title><link rel="stylesheet" href="<?=Html::e(Url::to('/public/assets/app.css'))?>"><link rel="stylesheet" href="<?=Html::e(Url::to('/public/assets/navigation.css'))?>"></head><body data-user-autocomplete-url="<?=Html::e(Url::to('/api/users/autocomplete'))?>">
<?php if($currentUser): ?>
<header class="topbar">
  <a class="brand" href="<?=Html::e(Url::to('/dashboard'))?>">imWiki</a>
  <form class="search" action="<?=Html::e(Url::to('/search'))?>" data-search-form data-suggestions-url="<?=Html::e(Url::to('/api/search/suggestions'))?>"><input name="q" placeholder="Szukaj w imWiki (Ctrl+K)" aria-label="Szukaj" autocomplete="off"><div class="search-suggestions" data-search-suggestions hidden></div></form>
  <nav class="primary-nav" aria-label="Główna nawigacja">
    <a href="<?=Html::e(Url::to('/spaces'))?>">Przestrzenie</a>
    <a href="<?=Html::e(Url::to('/recent'))?>">Ostatnie</a>
    <a href="<?=Html::e(Url::to('/drafts'))?>">Szkice</a>
    <a href="<?=Html::e(Url::to('/tasks'))?>">Zadania</a>
    <a class="notification-link" href="<?=Html::e(Url::to('/notifications'))?>" aria-label="Powiadomienia">Powiadomienia<?php if($notificationCount>0): ?><span class="count-badge"><?=Html::e($notificationCount>99?'99+':(string)$notificationCount)?></span><?php endif; ?></a>
    <details class="nav-menu">
      <summary>Konto</summary>
      <div class="nav-menu-panel">
        <span class="nav-section-label nav-mobile-label">Wiki</span>
        <a class="nav-mobile-label" href="<?=Html::e(Url::to('/spaces'))?>">Przestrzenie</a>
        <a class="nav-mobile-label" href="<?=Html::e(Url::to('/recent'))?>">Ostatnie</a>
        <a class="nav-mobile-label" href="<?=Html::e(Url::to('/drafts'))?>">Szkice</a>
        <a class="nav-mobile-label" href="<?=Html::e(Url::to('/tasks'))?>">Zadania</a>
        <span class="nav-section-label">Konto</span>
        <a href="<?=Html::e(Url::to('/profile'))?>">Profil</a>
        <a href="<?=Html::e(Url::to('/profile/notifications'))?>">Ustawienia powiadomień</a>
        <a href="<?=Html::e(Url::to('/profile/security'))?>">Bezpieczeństwo i 2FA</a>
        <a href="<?=Html::e(Url::to('/profile/sessions'))?>">Aktywne sesje</a>
        <a href="<?=Html::e(Url::to('/api-tokens'))?>">Tokeny API</a>
        <form method="post" action="<?=Html::e(Url::to('/logout'))?>"><?=Csrf::field()?><button class="link-button">Wyloguj</button></form>
      </div>
    </details>
    <?php if($isAdmin): ?>
    <details class="nav-menu">
      <summary>Administracja</summary>
      <div class="nav-menu-panel">
        <span class="nav-section-label">System</span>
        <a href="<?=Html::e(Url::to('/admin/system'))?>">Ustawienia systemu</a>
        <a href="<?=Html::e(Url::to('/admin/users'))?>">Użytkownicy i grupy</a>
        <a href="<?=Html::e(Url::to('/admin/content'))?>">Stan treści</a>
        <a href="<?=Html::e(Url::to('/admin/security'))?>">Panel bezpieczeństwa</a>
        <span class="nav-section-label">Operacje</span>
        <a href="<?=Html::e(Url::to('/admin/mail'))?>">Poczta</a>
        <a href="<?=Html::e(Url::to('/admin/backup'))?>">Backup i restore</a>
        <a href="<?=Html::e(Url::to('/admin/database'))?>">Baza i cache</a>
        <a href="<?=Html::e(Url::to('/admin/logs'))?>">Logi</a>
        <a href="<?=Html::e(Url::to('/admin/retention'))?>">Retencja</a>
      </div>
    </details>
    <?php endif; ?>
    <span class="user account-name"><?=Html::e(trim($currentUser['first_name'].' '.$currentUser['last_name'])?:$currentUser['username'])?></span>
  </nav>
</header>
<?php endif; ?>
<main class="app-shell"><?=$content?></main><script src="<?=Html::e(Url::to('/public/assets/app.js'))?>" defer></script></body></html>
