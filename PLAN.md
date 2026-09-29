# vuthaihoc/laravel-xtdb2: plan

A Laravel 12/13 database driver for [XTDB 2](https://github.com/xtdb/xtdb) (bitemporal, schemaless,
PostgreSQL wire protocol), in the family of `vuthaihoc/cockroachdb-laravel` and `vuthaihoc/laravel-matrixone`.

## Feasibility (2026-09-29)

Tested on XTDB **2.2.0-beta3** (target) and **2.1.0** (last stable) with raw PDO probes and a
prototype driver running real Eloquent code (`scratch/`). Verdict: **feasible for XTDB 2.2+**, with
documented limits. The prototype already passes 50 of 59 Laravel scenarios on 2.2.0-beta3 with
four small classes.

### What works through Laravel's `pgsql` stack (with the prototype)

- Eloquent CRUD, `save()` updates, `increment()`, soft deletes / restore / force delete
- Casts: boolean, datetime, float, array (see JSON below), timestamps
- Relations: `with()`, `whereHas()`, `withCount()`, `belongsTo`, `hasMany`
- `where`/`whereIn`/`whereNull`/`whereLike`/`whereDate`/`whereYear`, joins, unions, subqueries,
  aggregates, `paginate()`, `chunk()`, `cursor()`, `latest()`, `distinct()`, `inRandomOrder()` (no-op order)
- `firstOrCreate()` / `updateOrCreate()` outside transactions
- `DB::transaction()` with writes only, rollback on exception
- `Schema::create()` / `Schema::table()` / `Schema::drop()` / `Schema::hasTable()` (2.2+)
- Bitemporal SQL: `FOR VALID_TIME AS OF`, `FOR SYSTEM_TIME AS OF`, `FOR ALL VALID_TIME`, `_valid_from`...

### Findings that shape the driver

| # | XTDB behaviour | Consequence / driver answer |
|---|---|---|
| 1 | Native prepared DML fails: *"client must specify types for all non-null params"* (PDO sends untyped params) | Emulated prepares, and the driver **inlines bindings itself as typed literals** |
| 2 | Parameters bound as strings stay strings: `false` → `''`, `PARAM_BOOL` → `'t'`, a date string is text; comparing a timestamp column that also holds text values crashes the query | `Literal`: `TRUE`/`FALSE`, `TIMESTAMP '...'` for `DateTimeInterface` **and date-shaped strings** (Eloquent formats dates to strings; opt-out option), `{...}`/`[...]` for arrays, `E'...'` strings |
| 3 | 2.1.0: emoji (non-BMP) in `INSERT ... VALUES` breaks the parser | `E'...\UXXXXXXXX'` escapes (fixed in 2.2.0-beta3; keep the escape, harmless) |
| 4 | `_id` is required; no autoincrement, no `lastval()`, `RETURNING` silently ignored | Primary key `_id`, ids generated client-side (ULID default); `insertGetId()` returns the generated id; `XtdbModel` trait / base model |
| 5 | `INSERT` with an existing `_id` **replaces the whole document**; `PATCH ... RECORDS {...}` merges | `upsert()` → `PATCH`/`INSERT ... RECORDS` when `uniqueBy` is `_id`; no unique constraints otherwise (throw) |
| 6 | A transaction is **read-only or write-only**, decided by its first statement; `ASSERT` is the only read inside a write | Biggest limit. Options: (a) reads inside a write transaction run on a second connection (not transactional, pending writes invisible); (b) throw a clear `XtdbTransactionException`. Default to (b), opt into (a) |
| 7 | No `SAVEPOINT` | Nested transactions flattened (as laravel-matrixone) |
| 8 | `update`/`delete` report **0 affected rows** | Documented; `update()` returns 0; optimistic locking cannot work |
| 9 | No DDL in 2.1.0; 2.2 has `CREATE TABLE t (a, b)` (no types, additive), no `ALTER`/`DROP`/indexes | Migrations declare tables/columns; types, indexes, keys, foreign keys are no-ops; `drop` = `ERASE ... WHERE TRUE` |
| 10 | 2.2: a table or column never declared nor written → *"Table/Column not found"* | Migrations must declare columns (`Schema::create`); `softDeletes()` etc. declare theirs |
| 11 | Eloquent `array`/`json` casts store **JSON text**, so `where('settings->theme', ...)` never matches | A driver cast (`AsDocument`) storing PHP arrays as native XTDB documents; `->` paths compile to `(col).key` |
| 12 | No `ilike`, `for update`, `on conflict`, `random()`, `gen_random_uuid()`, `pg_typeof`; `date_trunc(day, x)` syntax; `HAVING` cannot use select aliases; no subquery in `UPDATE ... SET` | Grammar overrides (`lower() like`, locks dropped, `whereJsonContains`); the rest documented |
| 13 | `set search_path to "public"` rejected (unquoted value only); Laravel's `information_schema`/`pg_class` queries use unsupported functions | Connector skips it; schema grammar/processor query `information_schema` and map XTDB types (`:i64`, `:utf8`, `[:? :instant]`...) |

## Plan

### Decisions (2026-09-29)
1. Minimum XTDB **2.2**, published as a pre-release until 2.2.0 is stable.
2. Reads inside a write transaction (finding 6): **throw** `XtdbTransactionException` with advice.
3. Models use **`_id`** (`HasXtdbKey`); a model keeping `id` gets a random integer stored in `id` and `_id`.
4. Date-and-time strings are timestamps by default (`dates_from_strings` to opt out).

### Phase 1: core driver (XTDB 2.2+) — done (57 tests on 2.2.0-beta3, Laravel 12 and 13)
Found while building it: `ERASE` fails on unknown tables, migrations must not run in transactions,
min/max/sum/avg fail on never-valued columns, `? = any(list) and ...` loses rows (worked around), and
columns keep their types after `ERASE`.
- `XtdbServiceProvider` (driver `xtdb`), `XtdbConnector` (emulated prepares, no search_path), `XtdbConnection`
- `Literal` binding inliner (findings 1–3) with unit tests: escaping, emoji, dates, arrays, enums, injection attempts
- Query grammar: insert/`insertGetId` with client ids, `upsert` via `PATCH`, `lower() like`, locks dropped,
  JSON paths `(col).key`, `whereJsonContains`, `truncate` → `ERASE`, `toRawSql` through `Literal`
- Processor: `insertGetId` returns the generated id; schema column types mapped
- Schema grammar/builder: `create`/`table` → `CREATE TABLE (cols)`, `drop` → `ERASE`, no-op indexes/keys,
  `hasTable`/`hasColumn`/`getTables`/`getColumns` on `information_schema`
- Transactions: write-only mode, clear exception on reads (finding 6), savepoints off (7)
- `Xtdb\Eloquent\XtdbModel` (or `HasXtdbKey` trait): `_id` key, ULID ids, `AsDocument` cast

### Phase 2: bitemporal API (the reason to use XTDB)
- Builder: `asOfValidTime()`, `asOfSystemTime()`, `forAllValidTime()`, `validBetween()`, `history()`
- Writes with valid time: `validFrom()`/`validTo()` on insert/update/delete (`FOR PORTION OF VALID_TIME`)
- `erase()` (GDPR hard delete) vs `delete()` (ends validity)
- Implements laravel-db-portable's `HistoricalReads` (`asOfTime()` → `FOR SYSTEM_TIME AS OF`)

### Phase 3: Laravel integration
- Migrations table, `RefreshDatabase`/`DatabaseTruncation`, cache/session/queue drivers where possible
  (queue needs locks: likely unsupported), Scout engine later
- Docs site, CI (PHP 8.2–8.4 × Laravel 12/13 × XTDB 2.2), Packagist

