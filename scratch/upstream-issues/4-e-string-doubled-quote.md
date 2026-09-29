# `E'...'` strings keep a doubled quote as two quotes (`E'it''s'` returns `it''s`)

## Summary

#3501 fixed doubled single quotes in standard string literals, but an escape string (`E'...'`) still keeps both
quotes. In PostgreSQL, a doubled quote is one quote in both forms; inside `E'...'`, `\'` also works (and does in XTDB).

## Reproduction

```sql
SELECT 'it''s'   AS plain,        -- it's    correct (#3501)
       E'it''s'  AS e_doubled,    -- it''s   WRONG, expected it's
       E'it\'s'  AS e_backslash;  -- it's    correct
```

## Expected

`it's` for all three.

## Actual

`{"plain": "it's", "e_doubled": "it''s", "e_backslash": "it's"}`

## Versions

Same result on 2.1.0 [ca78d56], 2.2.0-beta3 [591d5c5] and nightly [662211f] (2026-09-28).

## Environment

```
Images:  ghcr.io/xtdb/xtdb:latest (2.1.0 [ca78d56]), :2.2.0-beta3 [591d5c5], :nightly [662211f] (2026-09-28)
Client:  PHP 8.3 PDO pgsql (emulated prepares, i.e. plain SQL text); reproduces with psql
```
