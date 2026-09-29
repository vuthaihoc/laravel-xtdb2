<?php
$pdo = new PDO('pgsql:host=127.0.0.1;port='.(getenv('XTDB_PORT') ?: '5435').';dbname=xtdb', 'xtdb', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function t(string $label, callable $fn) { try { $r = $fn(); echo "OK   $label".($r !== null ? ' => '.substr(json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), 0, 260) : '')."\n"; } catch (Throwable $e) { echo "FAIL $label => ".preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 180))."\n"; } }
$run = function (string $sql, array $binds = []) use ($pdo) { $s = $pdo->prepare($sql); foreach ($binds as $i => [$v, $type]) { $s->bindValue($i + 1, $v, $type); } $s->execute(); return $s->columnCount() ? $s->fetchAll() : null; };
$p = uniqid('q');
$types = fn (string $table) => $run("select column_name, data_type from information_schema.columns where table_name = '{$table}' and column_name not like '\\_%' escape '\\' order by column_name");

echo "--- booleans\n";
t('PARAM_BOOL true/false', fn () => $run("insert into \"{$p}_b\" (\"_id\", \"t\", \"f\") values (1, ?, ?)", [[true, PDO::PARAM_BOOL], [false, PDO::PARAM_BOOL]]));
t('literal TRUE/FALSE', fn () => $run("insert into \"{$p}_b\" (\"_id\", \"t\", \"f\") values (2, true, false)"));
t('cast(? as boolean)', fn () => $run("insert into \"{$p}_b\" (\"_id\", \"t\", \"f\") values (3, cast(? as boolean), cast(? as boolean))", [['1', PDO::PARAM_STR], ['0', PDO::PARAM_STR]]));
t('bool rows', fn () => $run("select \"_id\", \"t\", \"f\" from \"{$p}_b\" order by \"_id\""));
t('bool types', fn () => $types("{$p}_b"));
t('where bool = ?', fn () => $run("select \"_id\" from \"{$p}_b\" where \"t\" = ? order by \"_id\"", [[true, PDO::PARAM_BOOL]]));

echo "--- numbers / strings\n";
t('float param as string', fn () => $run("insert into \"{$p}_n\" (\"_id\", \"price\") values (1, ?)", [['9.5', PDO::PARAM_STR]]));
t('float cast', fn () => $run("insert into \"{$p}_n\" (\"_id\", \"price\") values (2, cast(? as double precision))", [['9.5', PDO::PARAM_STR]]));
t('decimal cast', fn () => $run("insert into \"{$p}_n\" (\"_id\", \"price\") values (3, cast(? as decimal))", [['9.50', PDO::PARAM_STR]]));
t('number types', fn () => $types("{$p}_n"));
t('quotes and backslashes', fn () => $run("insert into \"{$p}_n\" (\"_id\", \"s\") values (4, ?)", [["it's a \\ back'slash", PDO::PARAM_STR]]));
t('read back string', fn () => $run("select \"s\" from \"{$p}_n\" where \"_id\" = 4"));
t('unicode', fn () => $run("insert into \"{$p}_n\" (\"_id\", \"s\") values (5, ?)", [['Xin chào 🚀', PDO::PARAM_STR]]));
t('read unicode', fn () => $run("select \"s\" from \"{$p}_n\" where \"_id\" = 5"));
t('? inside string literal', fn () => $run("select '?' as q, ? as v", [['x', PDO::PARAM_STR]]));

echo "--- timestamps\n";
t('timestamp literal', fn () => $run("insert into \"{$p}_t\" (\"_id\", \"at\") values (1, timestamp '2026-01-02 03:04:05')"));
t('timestamptz literal', fn () => $run("insert into \"{$p}_t\" (\"_id\", \"at\") values (2, timestamp '2026-01-02 03:04:05+07:00')"));
t('date literal', fn () => $run("insert into \"{$p}_t\" (\"_id\", \"d\") values (3, date '2026-01-02')"));
t('cast(? as date)', fn () => $run("insert into \"{$p}_t\" (\"_id\", \"d\") values (4, cast(? as date))", [['2026-01-02', PDO::PARAM_STR]]));
t('timestamp types', fn () => $types("{$p}_t"));
t('compare ts column with string param', fn () => $run("select \"_id\" from \"{$p}_t\" where \"at\" > ? order by \"_id\"", [['2026-01-01 00:00:00', PDO::PARAM_STR]]));
t('compare ts column with cast param', fn () => $run("select \"_id\" from \"{$p}_t\" where \"at\" > cast(? as timestamp with time zone) order by \"_id\"", [['2026-01-01 00:00:00+00:00', PDO::PARAM_STR]]));
t('date part functions', fn () => $run("select extract(year from \"at\") as y, extract(month from \"at\") as m from \"{$p}_t\" where \"_id\" = 1"));
t('date_trunc(day, x)', fn () => $run("select date_trunc(day, \"at\") as d from \"{$p}_t\" where \"_id\" = 1"));
t('cast(x as date)', fn () => $run("select cast(\"at\" as date) as d from \"{$p}_t\" where \"_id\" = 1"));

echo "--- nested / json\n";
t('object literal in values', fn () => $run("insert into \"{$p}_j\" (\"_id\", \"meta\") values (1, {source: 'yt', tags: ['a', 'b']})"));
t('object with param inside', fn () => $run("insert into \"{$p}_j\" (\"_id\", \"meta\") values (2, {source: ?, n: ?})", [['x', PDO::PARAM_STR], [5, PDO::PARAM_INT]]));
t('array literal with params', fn () => $run("insert into \"{$p}_j\" (\"_id\", \"tags\") values (3, [?, ?])", [['a', PDO::PARAM_STR], ['b', PDO::PARAM_STR]]));
t('records {..} with params', fn () => $run("insert into \"{$p}_j\" records {_id: 4, meta: {source: ?}}", [['z', PDO::PARAM_STR]]));
t('json string stays string', fn () => $run("insert into \"{$p}_j\" (\"_id\", \"meta\") values (5, ?)", [['{"source": "s"}', PDO::PARAM_STR]]));
t('nested types', fn () => $types("{$p}_j"));
t('where nested field = ?', fn () => $run("select \"_id\" from \"{$p}_j\" where (\"meta\").\"source\" = ? order by \"_id\"", [['x', PDO::PARAM_STR]]));
t('nested path 2 levels', fn () => $run("select (\"meta\").\"tags\"[1] as first_tag from \"{$p}_j\" where \"_id\" = 1"));
t('array contains', fn () => $run("select \"_id\" from \"{$p}_j\" where 'a' = any(\"tags\")"));
t('read nested as json', fn () => $run("select \"meta\" from \"{$p}_j\" where \"_id\" = 1"));

echo "--- patch / upsert\n";
t('patch into (cols) values', fn () => $run("patch into \"{$p}_j\" (\"_id\", \"n\") values (1, ?)", [[7, PDO::PARAM_INT]]));
t('patch into records {..?}', fn () => $run("patch into \"{$p}_j\" records {_id: 1, extra: ?}", [['e', PDO::PARAM_STR]]));
t('row after patch', fn () => $run("select \"_id\", \"n\", \"extra\", (\"meta\").\"source\" as src from \"{$p}_j\" where \"_id\" = 1"));
t('update set nested field', fn () => $run("update \"{$p}_j\" set \"meta\" = {source: ?} where \"_id\" = 2", [['upd', PDO::PARAM_STR]]));

echo "--- other\n";
t('lower() like lower(?)', fn () => $run("select count(*) as n from \"{$p}_n\" where lower(\"s\") like lower(?)", [['XIN%', PDO::PARAM_STR]]));
t('not like / like escape', fn () => $run("select count(*) as n from \"{$p}_n\" where \"s\" like ? escape '!'", [['%!%%', PDO::PARAM_STR]]));
t('count distinct', fn () => $run("select count(distinct \"_id\") as n from \"{$p}_n\""));
t('random()', fn () => $run('select random() as r'));
t('gen_random_uuid()', fn () => $run('select gen_random_uuid() as u'));
t('assert statement', fn () => $run("assert not exists (select 1 from \"{$p}_n\" where \"_id\" = 999)"));
t('begin read only', function () use ($pdo, $run) { $pdo->exec('begin read only'); try { return $run("select count(*) as n from \"{$p}_n\""); } finally { $pdo->exec('rollback'); } });
t('begin read write + only writes', function () use ($pdo, $run, $p) { $pdo->beginTransaction(); $run("insert into \"{$p}_x\" (\"_id\") values (1)"); $run("update \"{$p}_x\" set \"v\" = 1 where \"_id\" = 1"); $pdo->commit(); return $run("select * from \"{$p}_x\""); });
