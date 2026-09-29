# `x = ANY(list)` followed by `AND` / `OR` evaluates to false (a following `= ANY` fails with `Unknown symbol: '_sq_N'`)

## Summary

A quantified comparison over a list, `x = ANY(list)`, is true on its own, but as the **left operand of `AND` or `OR`**
it evaluates to false, whatever the right operand. With parentheses around the comparison the result is correct.
Two quantified comparisons joined by `AND` fail with an internal symbol error. It looks as if `ANY( … )` absorbs the
rest of the boolean expression, as if it were parsed as `x = ANY((list) AND …)` or as a subquery form.

## Reproduction

```sql
INSERT INTO docs RECORDS {_id: 1, tags: ['a', 'b']};

SELECT _id FROM docs WHERE 'a' = ANY(tags);                   -- [1]  correct
SELECT _id FROM docs WHERE 'a' = ANY(tags) AND TRUE;          -- []   WRONG, expected [1]
SELECT _id FROM docs WHERE 'a' = ANY(tags) AND _id = 1;       -- []   WRONG
SELECT _id FROM docs WHERE 'a' = ANY(tags) OR FALSE;          -- []   WRONG
SELECT _id FROM docs WHERE TRUE AND 'a' = ANY(tags);          -- [1]  correct (ANY is the right operand)
SELECT _id FROM docs WHERE ('a' = ANY(tags)) AND TRUE;        -- [1]  correct on 2.2 (parenthesised)

SELECT ('a' = ANY(tags))          AS a,   -- true
       ('a' = ANY(tags) AND TRUE) AS b,   -- false   WRONG
       (TRUE AND 'a' = ANY(tags)) AS c    -- true
FROM docs;

SELECT 'a' = ANY(ARRAY['a']) AND TRUE;    -- false, no table involved

SELECT _id FROM docs WHERE 'a' = ANY(tags) AND 'b' = ANY(tags);
-- ERROR: Unknown symbol: '_sq_4'
```

## Expected

`true` / `[1]` for every query above, as in PostgreSQL: `=` binds tighter than `AND` and `OR`, so
`x = ANY(list) AND y` is `(x = ANY(list)) AND y`.

## Actual

`false` / `[]` whenever `x = ANY(list)` is followed by `AND` or `OR`; an internal error for two chained quantified
comparisons.

## Versions

| Version | `'a' = ANY(tags) AND TRUE` | `('a' = ANY(tags)) AND TRUE` | `… AND 'b' = ANY(tags)` |
|---|---|---|---|
| 2.1.0 [ca78d56] | `[]` | `[]` | `Unknown symbol: '_sq_3'` |
| 2.2.0-beta3 [591d5c5] | `[]` | `[1]` | `Unknown symbol: '_sq_4'` |
| nightly [662211f], 2026-09-28 | `[]` | `[1]` | `Unknown symbol: '_sq_4'` |

Possibly related, none describing this: #2325 (quantified comparisons and NULLs), #2094 (E2E support for quantified
comparison predicates), #6024 (ambiguous decisions in `Sql.g4`), #5031 (boolean precedence stratification).

## Environment

```
Images:  ghcr.io/xtdb/xtdb:latest (2.1.0), :2.2.0-beta3, :nightly (2026-09-28)
Client:  PHP 8.3 PDO pgsql (emulated prepares, i.e. plain SQL text); reproduces with psql
```

Found while building a Laravel driver ([vuthaihoc/laravel-xtdb2](https://github.com/vuthaihoc/laravel-xtdb2)):
Laravel's `whereJsonContains()` compiles to `? = ANY(list)`, and it is usually followed by more conditions (a
soft-delete scope, other filters). The driver now wraps it in parentheses.
