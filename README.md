# XTDB driver for Laravel

A Laravel 12 / 13 database driver for [XTDB 2](https://github.com/xtdb/xtdb): Eloquent, the query builder and
migrations over XTDB's PostgreSQL wire protocol.

> **Pre-release.** Requires **XTDB 2.2** (tested on `2.2.0-beta3`): migrations need its `CREATE TABLE`.

XTDB is not PostgreSQL: tables are schemaless, every row has an `_id`, all history is kept (bitemporal), and a
transaction either reads or writes. The driver maps Laravel onto that model; the [limits](#limits) are listed below.

## Installation

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

## Models

Every XTDB row is identified by `_id`, and XTDB generates no keys. Use `HasXtdbKey`: the key is `_id`, a ULID
generated when the model is created.

```php
use Illuminate\Database\Eloquent\Model;
use LaravelXtdb\Eloquent\Casts\AsDocument;
use LaravelXtdb\Eloquent\HasXtdbKey;

class User extends Model
{
    use HasXtdbKey;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'settings' => AsDocument::class,   // a queryable nested document
        ];
    }
}

User::create(['name' => 'Alice', 'settings' => ['theme' => 'dark', 'tags' => ['a']]]);
User::where('settings->theme', 'dark')->whereJsonContains('settings->tags', 'a')->get();
```

- Relations, soft deletes, casts, pagination, `chunk()`, `cursor()`, `firstOrCreate()` and `updateOrCreate()` work
  as usual. Foreign keys are ordinary columns holding the related `_id`.
- A model keeping Laravel's incrementing `id` gets a random positive 64-bit integer, stored as `id` and `_id`.
- `DB::table()->insert()` adds a ULID `_id` to rows without one; `insertGetId()` returns the generated key.
- Laravel's `array`/`json` casts store JSON **text**, whose fields XTDB cannot query: use `AsDocument`, or bind
  `new LaravelXtdb\Query\Document([...])` in the query builder.

## Migrations

XTDB tables are schemaless. A migration declares a table and its columns, so queries can name them before any row
exists (XTDB rejects unknown tables and columns):

```php
Schema::create('users', function (Blueprint $table) {
    $table->ulid('_id')->primary();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamps();
    $table->softDeletes();
});
// create table "users" ("_id", "name", "email", "created_at", "updated_at", "deleted_at")
```

| Blueprint | On XTDB |
|---|---|
| `create()`, `table()` adding columns | `create table t (columns)`: declares the table and the columns, types are ignored |
| types, `nullable()`, `default()`, `change()` | nothing (defaults are not applied: set them in the model's `$attributes`) |
| indexes, `primary()`, `unique()`, foreign keys | nothing: XTDB has none |
| `drop()`, `dropIfExists()`, `truncate()`, `migrate:fresh` | `erase from t where true`: rows and their history are removed, the table name stays |
| `dropColumn()` | nothing: existing values stay |
| `rename()`, `renameColumn()` | `UnsupportedFeatureException` |

Migrations do not run in transactions (XTDB DDL is not transactional).

## Values and types

XTDB rejects untyped parameters in writes and keeps values sent as text as text, so the driver writes every binding
as a typed XTDB literal: `TRUE`/`FALSE`, numbers, `TIMESTAMP '...'` for `DateTimeInterface`, `{...}` / `[...]` for
documents, and escaped `E'...'` strings. Eloquent formats dates to strings (`2026-01-02 03:04:05`), so strings with a
date **and** a time are sent as timestamps too; set `'dates_from_strings' => false` to keep them text.
`toRawSql()` shows the SQL the driver sends.

Keep one type per column: XTDB compares values by type, and remembers a column's types even after its rows are
erased (an `_id` that was once an integer and once a string cannot be sorted).

## Transactions

An XTDB transaction is **read-only or read-write**, decided by its first statement, and a read-write transaction runs
no queries. Inside `DB::transaction()`, only write:

```php
$user = User::where('email', $email)->firstOrFail();   // read first

DB::transaction(function () use ($user) {              // then write
    $user->update(['name' => 'Alice']);
    Post::create(['user_id' => $user->getKey(), 'title' => 'Hello']);
});
```

A query after a write, or a write after a query, throws `LaravelXtdb\Exceptions\XtdbTransactionException` with this
advice. Nested transactions are part of the outer one (XTDB has no savepoints).

## Limits

| Laravel | XTDB |
|---|---|
| `update()` / `delete()` return values | always 0 (XTDB reports no affected rows) |
| `insert()` of an existing `_id` | replaces the whole row |
| `upsert()` | `PATCH`, matched by `_id` only; existing rows get every given column |
| `insertOrIgnore()`, `inRandomOrder()`, JSON path updates (`update(['a->b' => ...])`), array indexes in JSON paths | `UnsupportedFeatureException` |
| `lockForUpdate()`, `sharedLock()` | ignored (no row locks) |
| `whereLike()` / `ilike` | `lower(col) like lower(?)` |
| window functions, `HAVING` on select aliases, subqueries in `UPDATE ... SET` | not supported by XTDB |
| `min`/`max`/`sum`/`avg` of a column that never held a value | `null` (XTDB raises an error; the driver returns null) |

XTDB 2.2.0-beta3 issues the driver works around: `? = any(list)` followed by another `AND` condition loses rows when
the list is null in other rows (compiled as `(? = any(list)) is true`), and `LIKE ... ESCAPE` matches nothing, so `%`
and `_` cannot be matched literally with `like` (the search helpers use `position()`).

## laravel-db-portable

The driver requires [laravel-db-portable](https://github.com/vuthaihoc/laravel-db-portable), and its query builder
implements that package's contracts:

- `HistoricalReads`: `asOfTime($time)` reads the `from` table as XTDB had stored it then
  (`FOR SYSTEM_TIME AS OF`; a DateTimeInterface, a timestamp or `'-10s'`), `readCurrent()` undoes it,
  `readStale()` reads current data (reads do not contend with writes).
- `SearchBox`: `whereStartsWith()`, `whereContains()` and `suggest()` compare `lower()` values with `position()`;
  XTDB has no full-text search, so `searchFullText()` and `*FullTextRelevance()` throw `UnsupportedFeatureException`.

```php
Order::query()->asOfTime(now()->subHour())->sum('total');   // the totals as they were an hour ago
Word::suggest('word', $search)->limit(10)->get();
```

## Testing

```bash
docker run -d --name xtdb-test -p 127.0.0.1:5435:5432 -p 127.0.0.1:8083:8080 ghcr.io/xtdb/xtdb:2.2.0-beta3
composer test        # Unit (no server) + Feature (XTDB on 127.0.0.1:5435)
composer test:known-issues   # XTDB issues the driver works around, asserting PostgreSQL behaviour: failures expected
composer phpstan
composer cs
```

## License

MIT
