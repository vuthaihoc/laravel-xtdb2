# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project Overview

`vuthaihoc/laravel-xtdb2`: a Laravel 12/13 database driver (`xtdb`) for XTDB 2.2+ over the PostgreSQL wire protocol, built on Laravel's `pgsql` classes. Sibling packages: `../laravel-matrixone`, `../crdb2025` (cockroachdb-laravel), `../laravel-db-portable`. `PLAN.md` holds the roadmap and the feasibility findings; `scratch/` the throwaway probes behind them (excluded from Pint).

## Commands

- `composer test` — PHPUnit. `Unit` needs no server; `Feature` needs XTDB on 127.0.0.1:5435 (`docker run -d --name xtdb-beta3 -p 127.0.0.1:5435:5432 -p 127.0.0.1:8083:8080 ghcr.io/xtdb/xtdb:2.2.0-beta3`, add `--tmpfs /var/lib/xtdb:uid=20000,gid=20000` for a disposable store). Feature tests must pass twice in a row (tables survive erasure).
- `composer test:known-issues` — `tests/KnownIssues`: XTDB bugs and PostgreSQL differences in plain SQL, asserting PostgreSQL behaviour. Excluded from `composer test`; failures are expected until XTDB fixes them (a test that starts passing means a workaround can be reconsidered). Add one for every new XTDB limit the driver works around.
- `cd docs && npm install && npm run build` — the VitePress documentation site (`docs/docs/*.md`), deployed to GitHub Pages by `.github/workflows/docs.yml`. Keep README and the docs pages in sync; the examples of `docs/docs/bitemporal.md` are exercised by `tests/Feature/BitemporalUseCasesTest.php`.
- `composer phpstan` (level 8, larastan), `composer cs` / `composer cs:fix` (Pint).

## Architecture

- `src/XtdbServiceProvider.php` — binds `db.connector.xtdb` and `Connection::resolverFor('xtdb')`.
- `src/Connectors/XtdbConnector.php` — emulated prepares; skips `search_path` (XTDB rejects a quoted identifier) and `synchronous_commit`.
- `src/XtdbConnection.php` — `runQueryCallback()` inlines every binding with `Query\Literal` (XTDB rejects untyped DML parameters; text stays text); `prepareBindings()` keeps PHP types; XTDB transaction-mode errors become `XtdbTransactionException`.
- `src/Query/Literal.php` — typed literals and the placeholder scanner (skips quoted strings, identifiers, comments; keeps `??` for PDO). Strings are `E'...'` with `\'` (XTDB reads `''` inside `E''` as two quotes) and non-BMP characters as `\UXXXXXXXX`.
- `src/Query/Document.php`, `src/Eloquent/Casts/AsDocument.php` — nested values as XTDB objects/lists (plain arrays get flattened by the query builder).
- `src/Query/Builder.php` — `_id` generation in `insert()`/`insertGetId()` (ULID; random int for another sequence); `aggregate()` returns null where XTDB fails on a never-valued column.
- `src/Query/Grammar.php` — no RETURNING/ILIKE/locks/`on conflict`/`random()`; `upsert` → `PATCH ... RECORDS`; JSON paths `(col)."key"`; `whereJsonContains` → `(? = any(list)) is true`; update/delete with limit or joins by `_id in (select ...)`; truncate → `ERASE`.
- `src/Query/Processor.php` — XTDB types (`:utf8`, `[:? :i64]`, `[:timestamp-local :micro]`...) mapped to PostgreSQL names.
- `src/Schema/Grammar.php`, `Builder.php` — `create`/`table` → `create table t (cols)` (2.2+, no types); drop → `ERASE` (`dropIfExists` checks `hasTable`: ERASE fails on unknown tables); indexes/keys are no-ops; `$transactions = false`; `information_schema` queries.
- `src/Eloquent/HasXtdbKey.php` — `_id` key via `HasUlids`.
- Bitemporal: `Query\Builder` keeps `$systemTime`/`$validTime` (read clauses, compiled by `Grammar::compileFrom()` between table and alias) and `$validPeriod` (writes: `_valid_from`/`_valid_to` values on insert, `FOR PORTION OF VALID_TIME` on update/delete via `compileWritePeriod()`); `erase()` → `compileErase()`. `src/Eloquent/Bitemporal.php` — `versions()`, `saveValidFrom()` (applies the period through `newModelQuery()`), `deleteValidFrom()`, `erase()`.
- Requires `vuthaihoc/laravel-db-portable` (`../laravel-db-portable`): `Query\Builder` implements its `HistoricalReads` (`asOfTime()` → `FOR SYSTEM_TIME AS OF`, compiled in `Grammar::compileFrom()` between table and alias) and `SearchBox` (`position()`-based; no full-text).

## XTDB pitfalls (2.2.0-beta3)

- A transaction is read-only or read-write, decided by the first statement; a read-write one runs no queries (only `ASSERT`).
- Unknown tables and columns are errors: migrations declare them; `ERASE` on an unknown table fails.
- A column keeps the types it ever held, even after `ERASE`: mixed `_id` types break `ORDER BY`.
- `min/max/sum/avg` over a never-valued column (type `:nothing`) raise errors.
- `? = any(list)` followed by `AND`/`OR` evaluates to false (parenthesise it); two chained ones fail with `Unknown symbol: '_sq_N'`; `any(coalesce(list, []))` crashes the server.
- `update`/`delete` report 0 affected rows; `RETURNING` is ignored; `insert` of an existing `_id` replaces the row.
- `LIKE ... ESCAPE` matches nothing (no literal `%`/`_`): use `position()`.
- No `ilike`, `for update`, `on conflict`, `random()`, window functions, subqueries in `UPDATE ... SET`, `HAVING` on aliases.

## Rules

- Every grammar or literal change needs a unit test, and a feature test against XTDB when behaviour depends on the server.
- Code comments and docs in English.
