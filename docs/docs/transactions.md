# Transactions

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
