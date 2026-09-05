# imWiki

imWiki to samodzielnie hostowana aplikacja wiki w PHP 8.2+ inspirowana sposobem pracy Confluence/GitBook. Najważniejszym założeniem wdrożeniowym jest instalacja bez terminala: gotowy ZIP zawiera wszystko wymagane do uruchomienia aplikacji.

## Instalacja

1. Pobierz `imwiki-x.x.x.zip` z artefaktu release.
2. Wypakuj zawartość na serwer WWW, np. do `public_html/imwiki/`.
3. Otwórz adres aplikacji, np. `https://example.org/imwiki/`.
4. Przejdź przez kreator instalacji.
5. Zaloguj się kontem administratora utworzonym w kreatorze.

Nie wykonujesz `composer install`, `npm install`, ręcznego SQL, migracji ani ręcznej edycji `.env`.

### Wymagania serwera

- PHP 8.2 lub nowszy,
- MySQL 8+ albo MariaDB 10.6+,
- rozszerzenia: PDO, `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`, `session`, `zip`,
- serwer HTTP (Apache jest najprościej obsługiwany przez dołączony `.htaccess`),
- prawo zapisu do `config/` i `storage/` podczas instalacji.

Installer sprawdza wymagania przed rozpoczęciem migracji. Po instalacji tworzy `storage/installed.lock`; publiczna ponowna instalacja zostaje zablokowana.

## Funkcje obecnej wersji

- sześciostopniowy instalator WWW i automatyczne migracje/upgrade,
- logowanie, reset hasła, TOTP 2FA, recovery codes, aktywne sesje i historia logowań,
- użytkownicy, grupy, role, RBAC oraz ACL Space/Page z restrictions,
- Spaces, drzewo stron, drag & drop, move/copy, archiwizacja, kosz i redirects slugów,
- edytor WYSIWYG z autosave, optimistic locking, @mentions, makrami, drag&drop/paste obrazów,
- historia wersji, diff treści i properties oraz bezpieczne restore,
- komentarze wątkowe, inline comments i reakcje,
- zadania, page properties, approvals/workflow i templates globalne/Space,
- wersjonowane attachments, multi-upload, preview/lightbox i SHA-256,
- powiadomienia in-app/e-mail, watches oraz daily/weekly digest przez DB-backed queue,
- wyszukiwanie v2, suggestions, saved searches i filtry w składni zapytania,
- public sharing domyślnie OFF z expiration/password/revoke,
- osobiste API tokens, `/api/v1`, OpenAPI oraz webhooki HMAC z ochroną SSRF,
- import/export Markdown/HTML/ZIP, backup administratora i content-health reports,
- request ID, audit v2, log viewer, retencja, maintenance mode, cache i diagnostyka DB/systemu,
- lokalne assety, tryb jasny/ciemny, responsive UI i release ZIP bez zależności runtime od Internetu.

## Bezpieczeństwo

Zapytania parametryzowane używają PDO prepared statements. Formularze zmieniające stan wymagają CSRF. Sesje używają HttpOnly, SameSite=Lax i Secure przy HTTPS. Logowanie ma rate limiting. Treść edytora jest sanitizowana przed zapisem. Upload weryfikuje rozmiar i MIME, nadaje losową nazwę fizyczną i zapisuje plik w `storage/uploads`, który jest blokowany przed bezpośrednim dostępem HTTP. Pobieranie odbywa się przez kontrolowany endpoint po sprawdzeniu ACL.

`config/config.php`, logi, migracje, prywatne pliki i storage są chronione przez `.htaccess`. W środowisku produkcyjnym błędy aplikacji nie wyświetlają stack trace ani danych DB.

## Aktualizacje bazy

Migracje znajdują się w `database/migrations/` i są rejestrowane w tabeli `migrations` z uwzględnieniem prefixu. Instalator uruchamia je automatycznie. Po aktualizacji plików aplikacja wykrywa niewykonane migracje przed normalnym request lifecycle. Administrator uruchamia je przez autoryzowany `upgrade.php`; migracja jest rejestrowana dopiero po powodzeniu.

## Backup

Pełne odtworzenie instalacji wymaga kopii:

- bazy MySQL/MariaDB,
- katalogu `storage/uploads/`,
- `config/config.php` oraz — jeżeli używane będą dodatkowe prywatne zasoby — `storage/private/`.

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

## Development

Do pracy nad kodem wystarcza PHP 8.2+ i baza MySQL/MariaDB. Projekt celowo nie wymaga frameworka ani runtime Composer/Node.

Testy:

```bash
php tests/run.php
```

Pełny smoke test instalacji wymaga bazy i zmiennych `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`:

```bash
php tests/install_smoke.php
```

GitHub Actions wykonuje syntax check, testy bezpieczeństwa, test niezainstalowanej aplikacji, świeżą instalację na MariaDB i kontrolę blokady `install.php` po instalacji.

## Release ZIP

Workflow `Release ZIP` tworzy artefakt `imwiki-x.y.z.zip`. ZIP nie zawiera `.git`, testów, runtime configu, logów ani danych użytkowników i nie wymaga Composera lub Node.js na serwerze produkcyjnym.

## Wersja

Aktualna wersja aplikacji: `0.2.0`.
