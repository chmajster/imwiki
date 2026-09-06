# imWiki

imWiki to samodzielnie hostowana aplikacja wiki w PHP 8.2+ inspirowana sposobem pracy Confluence/GitBook. Najważniejszym założeniem wdrożeniowym jest instalacja bez terminala: gotowy ZIP zawiera wszystko wymagane do uruchomienia aplikacji.

## Instalacja

1. Pobierz `imwiki-x.x.x.zip` z artefaktu release.
2. Wypakuj zawartość na serwer WWW, np. do `public_html/imwiki/`.
3. Otwórz adres aplikacji, np. `https://example.org/imwiki/`.
4. Przejdź przez sześciostopniowy kreator instalacji.
5. Zaloguj się kontem administratora utworzonym w kreatorze.

Nie wykonujesz `composer install`, `npm install`, ręcznego SQL, migracji ani ręcznej edycji `.env`.

### Wymagania serwera

- PHP 8.2 lub nowszy,
- MySQL 8+ albo MariaDB 10.6+,
- rozszerzenia: PDO, `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`, `session`, `zip`,
- serwer HTTP z rewrite do `index.php`,
- prawo zapisu do `config/` i `storage/` podczas instalacji.

Installer sprawdza wymagania przed rozpoczęciem migracji. Po instalacji tworzy `storage/installed.lock`; publiczna ponowna instalacja zostaje zablokowana. Jeżeli instalacja przerwie się po zapisaniu konfiguracji, wznowienie jest dopuszczone wyłącznie w tej samej sesji instalatora.

## Funkcje wersji 0.3

### Wiki i współpraca

- Spaces, kategorie Spaces, lifecycle i osobiste Spaces sterowane feature flagą,
- drzewo stron, drag & drop, move/copy, archiwizacja, kosz i redirects slugów,
- edytor z autosave, optimistic locking, `@mentions`, makrami i historią wersji,
- diff treści i properties oraz bezpieczne restore,
- komentarze wątkowe, inline comments, reakcje, zadania i watches,
- wersjonowane attachments z SHA-256, preview i kontrolą ACL,
- workflow Draft → Review → Approved → Published,
- page properties oraz wersjonowane structured property schemas,
- content governance: owner, klasyfikacje, review schedule, deprecation, legal hold, relations i bulk operations.

### Uwierzytelnianie i bezpieczeństwo

- lokalne logowanie, reset hasła, TOTP 2FA, recovery codes i aktywne sesje,
- LDAP/Active Directory jako provider hasłowy,
- OIDC Authorization Code + PKCE, state, nonce, walidacja podpisu RS256 i external identity mapping,
- bezpieczny lokalny provider pozostaje awaryjną ścieżką administracyjną,
- users/groups/roles, Super Administrator, RBAC i ACL Space/Page,
- polityki sesji, haseł, 2FA, trusted proxies, HSTS i CSP,
- reguły IP/CIDR dla dostępu globalnego i administracyjnego,
- request/correlation ID, rate limiting, CSRF, prepared statements, HTML sanitization i kontrolowane uploady,
- ochrona SSRF dla outbound HTTP oraz szyfrowanie sekretów aplikacyjnych.

### Enterprise / administracja

- Enterprise Administration dla lifecycle, governance, ownership i classification,
- diagnostyka efektywnych uprawnień ACL,
- feature flags globalne/Space,
- plugin manager z manifestem, kompatybilnością, Safe Mode, Macro Registry i event hooks,
- DB-backed job queue z retry/backoff/dead jobs,
- storage quotas, integrity checks i wykrywanie orphan files,
- audit log z filtrami, maskowaniem sekretów i eksportem CSV/JSON,
- `/health` oraz `/readiness` do monitoringu,
- search abstraction z MySQL FULLTEXT, ACL-aware wynikami, filtrami i attachment search.

### Integracje i API

- osobiste API tokens przechowujące wyłącznie hash tokena,
- zachowane kompatybilne `/api/v1`,
- `/api/v2` z bearer scopes, ACL, stabilnym UUID stron, cursor pagination, request ID i jednolitym formatem błędów,
- webhooki HMAC z ochroną SSRF i kolejką dostaw,
- public sharing domyślnie OFF z expiration/password/revoke,
- import/export Markdown/HTML/ZIP i backup administratora.

## Bezpieczeństwo

Zapytania parametryzowane używają PDO prepared statements. Formularze zmieniające stan wymagają CSRF. Sesje używają HttpOnly, SameSite=Lax i Secure przy HTTPS. Logowanie ma rate limiting. Treść edytora jest sanitizowana przed zapisem. Upload weryfikuje rozmiar i MIME, nadaje losową nazwę fizyczną i zapisuje plik poza bezpośrednim routingiem aplikacji. Pobieranie odbywa się przez kontrolowany endpoint po sprawdzeniu ACL.

OIDC używa PKCE, `state`, `nonce`, HTTPS discovery i walidacji podpisu ID tokena. Outbound HTTP rozwiązuje host i blokuje niedozwolone cele przez SSRF guard. Sekrety providerów są szyfrowane kluczem aplikacji. Wrażliwe dane są maskowane w audit/logach.

`config/config.php`, logi, migracje, prywatne pliki i storage są chronione przed bezpośrednim dostępem. W środowisku produkcyjnym błędy aplikacji nie wyświetlają stack trace ani danych DB.

## Aktualizacja z 0.2 do 0.3

1. Wykonaj backup bazy, `config/config.php`, `storage/uploads/` oraz `storage/private/`.
2. Podmień pliki aplikacji na zawartość nowego ZIP-a, zachowując config i storage.
3. Otwórz aplikację. Przy niewykonanych migracjach zwykłe requesty zostaną zatrzymane.
4. Zaloguj się jako administrator i przejdź do `upgrade.php`.
5. Sprawdź plan migracji i uruchom upgrade.
6. Po zakończeniu zweryfikuj `/readiness` i panel administracyjny.

Migrator używa blokady DB, zapisuje stan `running/failed/idle`, rejestruje migrację dopiero po jej powodzeniu i zachowuje istniejące dane. Upgrade 0.2 → 0.3 jest testowany automatycznie na MariaDB i MySQL.

## Backup

Pełne odtworzenie instalacji wymaga kopii:

- bazy MySQL/MariaDB,
- katalogu `storage/uploads/`,
- `config/config.php`,
- `storage/private/` jeśli zawiera prywatne zasoby, kwarantannę lub eksporty.

Nie zapisuj backupów w publicznie dostępnym katalogu aplikacji.

## Nginx

Przykładowy wariant dla instalacji w katalogu `/var/www/imwiki`:

```nginx
server {
    listen 80;
    server_name wiki.example.org;
    root /var/www/imwiki;
    index index.php;

    location ~ ^/(app|config|database|storage|tests)/ { deny all; }
    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

Dostosuj socket PHP-FPM do systemu. Nginx nie jest wymagany do standardowej instalacji.

## Development i testy

Projekt celowo nie wymaga frameworka ani runtime Composer/Node.

Podstawowe testy:

```bash
php tests/run.php
```

CI wykonuje:

- PHP syntax + security/unit tests na PHP 8.2, 8.3 i 8.4,
- JavaScript i OpenAPI JSON integrity,
- świeżą instalację migracji na MariaDB 10.11 i MySQL 8,
- upgrade fixture Etap 2 / 0.2 → Etap 3 / 0.3 z kontrolą zachowania danych,
- finalny release ZIP browser smoke: budowa produkcyjnego ZIP-a, rozpakowanie do pustego katalogu, instalacja wyłącznie przez HTTP, logowanie, utworzenie Space i strony, UUID, `/health`, `/readiness` oraz Enterprise Administration.

## Release ZIP

Workflow `Release ZIP` tworzy artefakt `imwiki-x.y.z.zip`. ZIP nie zawiera `.git`, testów, runtime configu, `installed.lock`, logów ani danych użytkowników i nie wymaga Composera lub Node.js na serwerze produkcyjnym.

## Wersja

Aktualna wersja aplikacji: `0.3.0`.
