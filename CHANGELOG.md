# Changelog

Wszystkie istotne zmiany imWiki są dokumentowane zgodnie z SemVer.

## [0.3.0] - 2026-09-06

### Added
- enterprise Space lifecycle, kategorie Spaces, owner/team metadata i opcjonalne Personal Spaces,
- klasyfikacje treści, legal hold, deprecation/replacement, review schedule, ownership reports, page relations i bulk governance,
- structured property schemas z wersjonowaniem i raportowaniem,
- Super Administrator oraz rozszerzone uprawnienia administracyjne,
- LDAP/Active Directory authentication provider,
- OIDC Authorization Code + PKCE z `state`, `nonce`, RS256 ID-token validation, external identities i auto-provisioning,
- globalne i Space-scoped feature flags,
- plugin framework z `plugin.json`, compatibility checks, Safe Mode, Macro Registry i event hooks,
- storage manager z quota checks, integrity verification i orphan-file diagnostics,
- rozszerzony DB-backed job queue z priorytetami, lease, retry/backoff, dedupe i dead jobs,
- audit service z filtrowaniem, maskowaniem sekretów i eksportem CSV/JSON,
- IP/CIDR access policy i trusted proxy handling,
- `/health`, `/readiness` i administracyjne health diagnostics,
- search engine abstraction, MySQL FULLTEXT i attachment search,
- `/api/v2` z bearer scopes, stable page UUID, cursor pagination, request ID i ujednoliconymi błędami,
- finalny release-ZIP browser smoke test instalujący aplikację przez HTTP z pustego katalogu.

### Changed
- wersja aplikacji podniesiona do `0.3.0`,
- bootstrap bezpieczeństwa korzysta ze wspólnego `SecurityHeaders`,
- logowanie Local/LDAP/OIDC jest spięte przez wspólną warstwę providerów przy zachowaniu lokalnego emergency access,
- Stage 3 routes są integrowane bez przebudowy istniejącego front controllera Etapu 2,
- CI obejmuje PHP 8.2/8.3/8.4 oraz MySQL 8 i MariaDB 10.11,
- migrator utrzymuje stan migracji i blokadę DB oraz wspiera kontrolowany retry po błędzie,
- instalator potrafi bezpiecznie wznowić częściową instalację wyłącznie w tej samej sesji.

### Fixed
- migracja 009 jest idempotentna wobec istniejącego schematu notifications/preferences,
- poprawiono brakujące runtime columns dla jobs i attachment quarantine,
- nazwy foreign keys w migracjach 004/005 są deterministycznie namespacowane prefixem, dzięki czemu wiele prefiksowanych instalacji/test fixtures może współistnieć w jednym schemacie MariaDB,
- MySQL 8 compatibility dla zastrzeżonej kolumny `system` w grupach,
- poprawiono końcowy krok instalatora oraz recovery po częściowym zapisie konfiguracji,
- poprawiono literówkę w finalnym release artifact smoke test.

### Security
- OIDC wymusza HTTPS discovery, PKCE, state/nonce, issuer/audience/expiry checks i podpis RS256,
- outbound HTTP używa SSRF guard oraz bezpośredniego połączenia do zweryfikowanego IP,
- reguły `X-Forwarded-*` są honorowane wyłącznie dla zaufanych proxy,
- plugin ZIP ma limity rozmiaru/liczby plików, kontrolę path traversal i allowlistę typów plików,
- upload może być podłączony do skanera i obsługuje kwarantannę,
- audit i job errors maskują dane wyglądające jak sekrety,
- ID aktywnej sesji PHP jest okresowo regenerowane co 15 minut, niezależnie od regeneracji wykonywanej podczas logowania; rotacja zachowuje dane zalogowanej sesji i nie zmienia 60-minutowego timeoutu bezczynności.

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
- rozszerzone fresh-install i upgrade smoke tests,
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
