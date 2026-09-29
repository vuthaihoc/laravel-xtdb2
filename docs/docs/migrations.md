# Migrations

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
