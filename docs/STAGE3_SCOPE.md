# imWiki Stage 3 — Enterprise Platform

Stage 3 rozszerza działającą aplikację Etapu 2. Nie jest osobnym prototypem ani nową aplikacją.

## Zakres wdrożony

- enterprise Space lifecycle, kategorie, owner/team metadata i Personal Spaces,
- content governance: classifications, legal hold, deprecation, review, ownership, relations i bulk actions,
- structured property schemas,
- Super Administrator, rozszerzony RBAC i diagnostyka efektywnych ACL,
- Local + LDAP/AD + OIDC authentication providers,
- external identity mapping, auto-provisioning i synchronizacja grup,
- security policy, trusted proxies i IP/CIDR restrictions,
- plugin framework, Safe Mode, Macro Registry i event hooks,
- storage quotas/integrity/orphan diagnostics i attachment scanner abstraction,
- rozbudowany DB-backed job queue,
- audit query/export z maskowaniem sekretów,
- health/readiness endpoints,
- search engine abstraction i MySQL FULLTEXT,
- kompatybilne `/api/v1` oraz nowe `/api/v2` oparte o bearer scopes i stabilne UUID,
- bezpieczne, idempotentne migracje z upgrade fixture 0.2 → 0.3,
- finalny ZIP instalowany i testowany przez HTTP bez aplikacyjnego CLI.

## Feature flags

Funkcje o większym wpływie operacyjnym są domyślnie kontrolowane przez `feature_flags`, między innymi plugins, OIDC, Personal Spaces, public sharing, approvals i API. Wyłączenie flagi nie kasuje danych funkcji.

## Upgrade

Migracje Stage 3 zaczynają się po schemacie Etapu 2 i zachowują istniejące rekordy. Migrator:

1. wykrywa pending migrations,
2. pobiera blokadę DB per baza + prefix,
3. zapisuje bieżący stan `running`,
4. oznacza migrację jako wykonaną dopiero po powodzeniu,
5. zapisuje bezpieczną diagnostykę przy błędzie,
6. wymaga jawnego retry po stanie `failed`.

Historyczne migracje używane również do nowych instalacji mają prefix-safe nazwy constraintów, co zapobiega kolizjom przy wielu prefiksach w jednym schemacie.

## Definition of Done / CI gate

PR Stage 3 nie powinien być uznany za gotowy, dopóki nie przechodzą jednocześnie:

- PHP syntax oraz unit/security tests na PHP 8.2, 8.3 i 8.4,
- OpenAPI JSON oraz JavaScript syntax,
- fresh migration install na MariaDB 10.11,
- fresh migration install na MySQL 8,
- upgrade fixture Etap 2 → Etap 3 na obu silnikach,
- final release ZIP browser smoke.

Release ZIP smoke buduje produkcyjny pakiet z tym samym zestawem wykluczeń co release workflow, rozpakowuje go do pustego katalogu, przechodzi `install.php` przez HTTP, sprawdza blokadę reinstalacji, loguje administratora, tworzy Space i stronę, weryfikuje UUID oraz `/health`, `/readiness` i Enterprise Administration.

## Bezpieczeństwo wdrożenia

Przed upgrade należy wykonać backup bazy, `config/config.php`, `storage/uploads/` i `storage/private/`. Feature flags powinny być włączane stopniowo po migracji. Local authentication pozostaje ścieżką awaryjną, dlatego konfiguracja LDAP/OIDC nie może odciąć administratora od systemu.
