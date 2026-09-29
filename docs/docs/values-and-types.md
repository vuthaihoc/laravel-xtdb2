# Values and types

XTDB rejects untyped parameters in writes and keeps values sent as text as text, so the driver writes every binding
as a typed XTDB literal: `TRUE`/`FALSE`, numbers, `TIMESTAMP '...'` for `DateTimeInterface`, `{...}` / `[...]` for
documents, and escaped `E'...'` strings. Eloquent formats dates to strings (`2026-01-02 03:04:05`), so strings with a
date **and** a time are sent as timestamps too; set `'dates_from_strings' => false` to keep them text.
`toRawSql()` shows the SQL the driver sends.

Keep one type per column: XTDB compares values by type, and remembers a column's types even after its rows are
erased (an `_id` that was once an integer and once a string cannot be sorted).
