# Installation

> **Pre-release.** Requires **XTDB 2.2** (tested on `2.2.0-beta3`): migrations need its `CREATE TABLE`.

XTDB is not PostgreSQL: tables are schemaless, every row has an `_id`, all history is kept (bitemporal), and a
transaction either reads or writes. The driver maps Laravel onto that model; see [Limits](./limits).

```bash
composer require vuthaihoc/laravel-xtdb2
```

```php
// config/database.php
'xtdb' => [
    'driver' => 'xtdb',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'xtdb'),
    'username' => env('DB_USERNAME', 'xtdb'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'sslmode' => 'disable',
    // 'dates_from_strings' => false,   // see "Values and types"
],
```

Run XTDB locally:

```bash
docker run -d --name xtdb -p 127.0.0.1:5432:5432 -p 127.0.0.1:8080:8080 ghcr.io/xtdb/xtdb:2.2.0-beta3
```
