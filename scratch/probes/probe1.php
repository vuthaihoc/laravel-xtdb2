<?php
$emulate = ($argv[1] ?? 'native') === 'emulate';
$pdo = new PDO('pgsql:host=127.0.0.1;port='.(getenv('XTDB_PORT') ?: '5435').';dbname=xtdb', 'xtdb', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => $emulate,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
echo "prepares: ".($emulate ? 'emulated' : 'native')."\n";
function t(string $label, callable $fn) { try { $r = $fn(); echo "OK   $label".($r !== null ? ' => '.json_encode($r, JSON_UNESCAPED_SLASHES) : '')."\n"; } catch (Throwable $e) { echo "FAIL $label => ".preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 220))."\n"; } }
$q = fn ($sql, $b = []) => (function () use ($pdo, $sql, $b) { $s = $pdo->prepare($sql); $s->execute($b); return $s->columnCount() ? $s->fetchAll() : $s->rowCount(); })();

t('version()', fn () => $q('select version() as v'));
t('server_version', fn () => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
// Laravel PostgresConnector::connect sends these
t("set names 'utf8'", fn () => $pdo->exec("set names 'utf8'"));
t("set time zone 'UTC'", fn () => $pdo->exec("set time zone 'UTC'"));
t('set search_path to "public"', fn () => $pdo->exec('set search_path to "public"'));
t("set application_name to 'laravel'", fn () => $pdo->exec("set application_name to 'laravel'"));
t("set session characteristics as transaction isolation level read committed", fn () => $pdo->exec("set session characteristics as transaction isolation level read committed"));

// CRUD
t('insert without _id', fn () => $q('insert into users (name) values (?)', ['a']));
t('insert with _id', fn () => $q('insert into users (_id, name, age) values (?, ?, ?)', [1, 'alice', 30]));
t('insert multi rows', fn () => $q('insert into users (_id, name, age) values (?, ?, ?), (?, ?, ?)', [2, 'bob', 25, 3, 'carol', null]));
t('insert returning', fn () => $q('insert into users (_id, name) values (?, ?) returning _id', [4, 'dave']));
t('select *', fn () => $q('select * from users order by _id'));
t('select where param', fn () => $q('select _id, name from users where age > ? order by _id', [20]));
t('select limit offset', fn () => $q('select _id from users order by _id limit 2 offset 1'));
t('count(*) aggregate', fn () => $q('select count(*) as aggregate from users'));
t('update', fn () => $q('update users set age = ? where _id = ?', [31, 1]));
t('update returning', fn () => $q('update users set age = age + 1 where _id = ? returning age', [1]));
t('delete', fn () => $q('delete from users where _id = ?', [4]));
t('select after update/delete', fn () => $q('select _id, name, age from users order by _id'));
t('quoted identifiers', fn () => $q('select "_id", "name" from "users" where "_id" = ?', [1]));
t('boolean param', fn () => $q('insert into flags (_id, on_off) values (?, ?)', [1, true]));
t('bool param as int', fn () => $q('insert into flags (_id, on_off) values (?, ?)', [2, 1]));
t('select flags', fn () => $q('select * from flags order by _id'));
t('json/nested insert', fn () => $q("insert into docs (_id, meta) values (1, {a: 1, b: [1,2]})"));
t('json param', fn () => $q('insert into docs (_id, meta) values (?, ?::jsonb)', [2, '{"a": 2}']));
t('select nested', fn () => $q('select _id, meta from docs order by _id'));
t('timestamp param', fn () => $q('insert into events (_id, at) values (?, ?)', [1, '2026-01-02 03:04:05']));
t('timestamptz cast', fn () => $q('insert into events (_id, at) values (?, cast(? as timestamp with time zone))', [2, '2026-01-02 03:04:05+07']));
t('select events', fn () => $q('select * from events order by _id'));

// Transactions
t('transaction commit', function () use ($pdo, $q) { $pdo->beginTransaction(); $q('insert into tx (_id) values (1)'); $pdo->commit(); return $q('select count(*) as n from tx'); });
t('transaction rollback', function () use ($pdo, $q) { $pdo->beginTransaction(); $q('insert into tx (_id) values (2)'); $pdo->rollBack(); return $q('select count(*) as n from tx'); });
t('read inside write txn', function () use ($pdo, $q) { $pdo->beginTransaction(); $q('insert into tx (_id) values (3)'); try { return $q('select count(*) as n from tx'); } finally { $pdo->rollBack(); } });
t('savepoint', function () use ($pdo) { $pdo->beginTransaction(); try { return $pdo->exec('savepoint trans2'); } finally { $pdo->rollBack(); } });
t('lastInsertId', fn () => $pdo->lastInsertId());
