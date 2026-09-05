# Architektura imWiki

## Request lifecycle

`index.php` weryfikuje stan instalacji, ładuje `bootstrap.php`, otwiera PDO, sprawdza pending migrations, tworzy zależności i przekazuje request do lekkiego routera. Przed wykonaniem kodu zależnego od nowego schematu istniejąca instalacja jest kierowana do autoryzowanego `upgrade.php`.

## Warstwy

- `Controllers` — HTTP, CSRF, autoryzacja, redirect/render.
- `Services` — logika biznesowa, transakcje i operacje domenowe.
- `Repositories` — odczyt encji i list z bazy.
- `Security` — ACL/RBAC, CSRF, rate limiting i sanitizer.
- `Database` — PDO i migration runner.
- `Support` — config, URL/base path, logger, event dispatcher.
- `View` + `templates` — renderowanie HTML bez SQL i logiki domenowej.

## Authorization

Model rozstrzyga dostęp globalnie, następnie na poziomie Space i Page. Restriction mode strony może dziedziczyć Space, ograniczyć dostęp do jawnych wpisów ACL albo ustawić stronę jako prywatną dla ownera i administratorów. Backend sprawdza ACL ponownie dla stron, wyszukiwania, załączników, powiadomień, zadań i API.

## Database i migrations

`database/migrations/*.php` jest jedynym źródłem zmian schematu. `Migrator` zapisuje ukończone migracje w tabeli `migrations`; migracja jest oznaczana jako wykonana dopiero po powodzeniu. Fresh install i upgrade używają tego samego runnera.

## Storage

Pliki użytkownika trafiają do `storage/uploads`, poza publicznym wykonaniem PHP. Pobranie odbywa się przez kontroler po ACL. Fizyczne nazwy są losowe; metadane i historia wersji są w SQL.

## Notifications i events

`EventDispatcher` odsprzęga zapis strony/komentarza od powiadomień. `NotificationService` przed wyświetleniem zdarzenia sprawdza, czy odbiorca nadal ma dostęp do targetu.

## Public sharing

Public sharing jest ustawieniem globalnym i domyślnie jest wyłączone. Token ma wysoką entropię, w bazie przechowywany jest SHA-256. Publiczny renderer nie korzysta z normalnego layoutu wiki i nie ujawnia drzewa, Spaces ani innych stron.

## Upgrade

Po podmianie plików aplikacja sprawdza pending migrations przed normalnym request lifecycle. Zwykły użytkownik otrzymuje 503, administrator jest kierowany do `upgrade.php`, gdzie uruchamia migracje po CSRF i autoryzacji.

## Release

GitHub Actions wykonuje lint/testy, fresh install na MariaDB i buduje ZIP bez `.git`, testów, runtime configu, logów i danych użytkownika. Produkcyjny serwer nie wymaga Composera ani Node.js.
