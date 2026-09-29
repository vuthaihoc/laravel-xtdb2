# `min`/`max`/`sum`/`avg` fail over a declared column that has no values yet, instead of returning NULL

## Summary

After `CREATE TABLE t (_id, batch)`, the column `batch` is known but has type `:nothing` until a row is written.
`count(batch)` returns 0, but `max(batch)` and `sum(batch)` raise errors. In SQL the aggregate of no values is NULL
(and `count` 0). Once a single row with `batch = NULL` exists, the same queries return NULL as expected.

Related to #6015 (min/max refused over a union-typed column); this case is the empty `:nothing` type that the column
declarations of `CREATE TABLE t (col, …)` (#4467, `7eced93be`) introduce.

## Reproduction

```sql
CREATE TABLE t (_id, batch);

SELECT count(batch) FROM t;   -- 0      correct
SELECT max(batch) FROM t;     -- ERROR  Incomparable types in min/max aggregate: #xt/type :nothing
SELECT sum(batch) FROM t;     -- ERROR  Cannot compute SUM over type Nothing

INSERT INTO t (_id, batch) VALUES (1, NULL);
SELECT max(batch), sum(batch) FROM t;   -- NULL, NULL   correct
```

## Expected

`NULL` for `max`, `min`, `sum` and `avg` over a column without values, as after the NULL row is inserted.

## Actual

```
ERROR:  Incomparable types in min/max aggregate: #xt/type :nothing
DETAIL: {"category":"incorrect","code":"xtdb.group-by/incomparable-min-max-types", ...}

ERROR:  Cannot compute SUM over type Nothing
```

## Versions

| Version | Result |
|---|---|
| 2.1.0 [ca78d56] | not applicable: no `CREATE TABLE`; an unknown column reads as NULL, so `max` is NULL |
| 2.2.0-beta3 [591d5c5] | errors above |
| nightly [662211f], 2026-09-28 | errors above |

## Environment

```
Images:  ghcr.io/xtdb/xtdb:latest (2.1.0 [ca78d56]), :2.2.0-beta3 [591d5c5], :nightly [662211f] (2026-09-28)
Client:  PHP 8.3 PDO pgsql (emulated prepares, i.e. plain SQL text); reproduces with psql
```

This is what a Laravel application hits on its first `php artisan migrate`: the migrator declares the `migrations`
table, then asks for `max(batch)` before any migration has run.
