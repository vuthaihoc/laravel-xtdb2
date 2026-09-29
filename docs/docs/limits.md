# Limits

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

## Known XTDB issues

`tests/KnownIssues` reproduces every XTDB limit the driver works around, as plain SQL asserting PostgreSQL behaviour.
The tests fail while an issue exists; one that starts passing after an XTDB upgrade means a workaround can be
reconsidered:

```bash
composer test:known-issues
```
