# Changelog

Wszystkie istotne zmiany imWiki są dokumentowane zgodnie z SemVer.

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
