# Changelog

Wszystkie istotne zmiany imWiki są dokumentowane zgodnie z SemVer.

## [0.3.0] - 2026-09-09

### Added
- spójny backup ZIP ze snapshotem SQL, używanymi uploadami, `metadata.json` i manifestem SHA-256,
- asynchroniczny lifecycle backupów `queued` / `running` / `ready` / `failed` / `expired`,
- kontrolowany restore offline z obowiązkową walidacją archiwum i jawnymi flagami `--apply --force`,
- CLI worker i scheduler do trwałego przetwarzania zadań poza requestem WWW,
- pełniejsze OpenAPI z request/response schemas, scope’ami tokenów i contract testem,
- paginowany endpoint drzewa stron z ACL oraz lazy loading gałęzi po stronie UI,
- `SlugService` jako jeden generator slugów dla tworzenia, edycji i przenoszenia stron,
- composition root `Bootstrap\Application` i centralny `RouteRegistrar`,
- testy integralności backupu, async backup lifecycle, upgrade, fresh install, security i reliability.

### Changed
- `index.php` jest minimalnym front controllerem; składanie zależności i routing przeniesiono poza entrypoint,
- frontend podzielono na moduły bez bundlera, a edytor korzysta z `Selection` / `Range` i DOM API zamiast `document.execCommand`,
- nawigację pogrupowano i uproszczono dla desktopu oraz urządzeń mobilnych,
- kolejka rezerwuje wyłącznie typy zadań obsługiwane przez aktualny runner,
- ciężkie zadania backupu są wykonywane wyłącznie przez CLI worker; opportunistyczny runner WWW ich nie konsumuje,
- import/eksport Markdown zachowuje nagłówki, listy, cytaty, kod, linki, obrazy, tabele/details i front matter,
- retencja usuwa wygasłe backupy i ich pliki oraz nadal czyści orphan uploads i stare logi,
- restore wersji strony odtwarza również page properties,
- archiwizacja zachowuje poprzedni status strony, a purge/trash działa na całym poddrzewie.

### Fixed
- naprawiono regresję składni w `MarkdownService` wykrytą przez CI,
- naprawiono rozjazdy ACL w search, public sharing, breadcrumbs, notifications i attachments,
- usunięto dwa niezależne silniki wyszukiwania na rzecz wspólnego `SearchQuery`,
- poprawiono retry, stale reservation recovery i filtrowanie typów zadań w kolejce,
- usunięto możliwość wykonania ciężkiego backupu podczas webowego shutdownu,
- poprawiono spójność revokacji sesji, resetów hasła i credentials,
- usunięto stały limit 500 elementów jako mechanizm renderowania drzewa Space.

### Security
- 2FA i kontrola sesji działają fail-closed,
- backup download wymaga uprawnień administracyjnych, sprawdza SHA-256 i używa `private, no-store`,
- public sharing pozostaje domyślnie wyłączony, a tokeny są przechowywane jako hashe,
- restore nie jest dostępny przez publiczny endpoint WWW,
- wyszukiwanie i tree API stosują ACL przed paginacją/limitem,
- CI blokuje ponowne użycie zdeprecjonowanego `document.execCommand`.

## [0.2.0] - 2026-09-05

### Added
- centrum powiadomień, `@mentions`, watch stron i Spaces,
- zadania i ekran „Moje zadania”,
- page properties,
- opcjonalny workflow Draft → Review → Approved → Published,
- rzeczywisty diff wersji,
- wersjonowanie załączników z SHA-256,
- page restrictions `inherited` / `specific` / `private`,
- public sharing domyślnie OFF, z tokenem, expiration, hasłem i revoke,
- osobiste API tokens i `/api/v1`,
- advanced search syntax oraz saved searches,
- request ID oraz rozszerzony audit,
- autoryzowany webowy upgrade bazy danych,
- rozszerzone fresh-install i upgrade smoke tests.
- TOTP 2FA, recovery codes, reset hasła i zarządzanie aktywnymi sesjami,
- CRUD użytkowników i grup, profil użytkownika i wymuszona zmiana hasła,
- threaded/inline comments, reactions, public shares, webhooks HMAC i SSRF guard,
- import/export, backup, content health, retention, log viewer i database diagnostics,
- page move/copy/trash/archive, backlinks, redirects oraz drag & drop drzewa,
- templates globalne/Space, recent pages, drafts, presence i konfigurowalny dashboard,
- drag&drop/paste upload obrazu, multi-upload i image lightbox,
- DB-backed jobs, notification digest i opportunistic scheduler.

### Changed
- instalator ma sześć etapów: Requirements, Database, Site, Administrator, Installation, Complete,
- aplikacja blokuje użycie nowego kodu przed wykonaniem wymaganych migracji,
- wyszukiwanie, notifications, attachments i breadcrumbs ponownie sprawdzają ACL strony.

### Fixed
- usunięto duplikat `can_delete` blokujący świeże tworzenie `page_permissions`,
- ujednolicono `can_comment` i `can_attachments` w ACL,
- usunięto możliwość ujawnienia tytułu niedostępnego parenta przez breadcrumbs.

### Security
- centralny sanitizer HTML whitelistuje tagi, atrybuty i URL schemes,
- public share przechowuje wyłącznie hash tokena i opcjonalny hash hasła,
- upload blokuje PHP, SVG i aktywne MIME,
- audit maskuje pola związane z hasłami, tokenami, sesją i sekretami.
