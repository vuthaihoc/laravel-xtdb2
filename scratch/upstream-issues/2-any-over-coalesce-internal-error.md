# Internal error `Cannot invoke "clojure.lang.IFn.invoke(Object)"` for `ANY(COALESCE(list, []))`

## Summary

Comparing with `ANY` over a `COALESCE` of a nullable list and an empty list literal fails with an internal error
(`xtdb.error/unknown`, category `fault`) instead of returning rows or a user-facing error.

## Reproduction

```sql
INSERT INTO docs RECORDS {_id: 1, tags: ['a']}, {_id: 2, tags: NULL};

SELECT _id FROM docs WHERE 'a' = ANY(COALESCE(tags, []));
```

## Expected

`[{_id: 1}]` (for row 2, `COALESCE(NULL, [])` is the empty list and `'a' = ANY([])` is false).

## Actual

```
ERROR:  Cannot invoke "clojure.lang.IFn.invoke(Object)"
DETAIL:  {"category":"fault","code":"xtdb.error/unknown","message":"Cannot invoke \"clojure.lang.IFn.invoke(Object)\""}
SQLSTATE: XX000
```

## Versions

| Version | Result |
|---|---|
| 2.1.0 [ca78d56] | `[]` (no error, but wrong: expected `[1]`) |
| 2.2.0-beta3 [591d5c5] | internal error above |
| nightly [662211f], 2026-09-28 | internal error above |

Same error text as #5948 (fixed by #5969: comparing a list column that holds only empty lists), which traced the
family to `519b33937`; this looks like a site that fix does not cover, reached through `COALESCE` with an empty list
literal. See also #5954.

## Environment

```
Images:  ghcr.io/xtdb/xtdb:latest (2.1.0 [ca78d56]), :2.2.0-beta3 [591d5c5], :nightly [662211f] (2026-09-28)
Client:  PHP 8.3 PDO pgsql (emulated prepares, i.e. plain SQL text); reproduces with psql
```
