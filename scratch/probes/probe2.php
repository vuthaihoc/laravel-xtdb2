<?php
$pdo = new PDO('pgsql:host=127.0.0.1;port='.(getenv('XTDB_PORT') ?: '5435').';dbname=xtdb', 'xtdb', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
function t(string $label, callable $fn) { try { $r = $fn(); echo "OK   $label".($r !== null ? ' => '.substr(json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), 0, 300) : '')."\n"; } catch (Throwable $e) { echo "FAIL $label => ".preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 200))."\n"; } }
// Bind like Laravel's Connection::bindValues: ints as PARAM_INT, the rest as strings.
$q = function (string $sql, array $b = []) use ($pdo) { $s = $pdo->prepare($sql); foreach (array_values($b) as $i => $v) { $s->bindValue($i + 1, $v, match (true) { is_int($v) => PDO::PARAM_INT, is_resource($v) => PDO::PARAM_LOB, default => PDO::PARAM_STR }); } $s->execute(); return $s->columnCount() ? $s->fetchAll() : ['rowCount' => $s->rowCount()]; };
$p = uniqid('p');

echo "--- writes, affected rows\n";
t('insert quoted keyword cols', fn () => $q("insert into \"{$p}_ev\" (\"_id\", \"at\", \"name\") values (?, ?, ?)", [1, '2026-01-02 03:04:05', 'x']));
t('timestamp literal type', fn () => $q("select \"at\", pg_typeof(\"at\") as t from \"{$p}_ev\""));
t('insert TIMESTAMP cast', fn () => $q("insert into \"{$p}_ev\" (\"_id\", \"at\") values (?, cast(? as timestamp))", [2, '2026-01-02 03:04:05']));
t('insert timestamptz cast', fn () => $q("insert into \"{$p}_ev\" (\"_id\", \"at\") values (?, cast(? as timestamp with time zone))", [3, '2026-01-02 03:04:05+07:00']));
t('select typed', fn () => $q("select \"_id\", \"at\" from \"{$p}_ev\" order by \"_id\""));
t('insert 3 rows', fn () => $q("insert into \"{$p}_u\" (\"_id\", \"name\", \"age\", \"active\") values (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)", [1,'alice',30,true, 2,'bob',25,false, 3,'carol',null,true]));
t('bool binds read back', fn () => $q("select \"_id\", \"active\", pg_typeof(\"active\") as t from \"{$p}_u\" order by \"_id\""));
t('update rowCount (2 rows)', fn () => $q("update \"{$p}_u\" set \"age\" = ? where \"age\" is not null", [40]));
t('delete rowCount (1 row)', fn () => $q("delete from \"{$p}_u\" where \"_id\" = ?", [3]));
t('insert existing _id', fn () => $q("insert into \"{$p}_u\" (\"_id\", \"name\") values (?, ?)", [1, 'alice2']));
t('row after re-insert', fn () => $q("select * from \"{$p}_u\" where \"_id\" = 1"));
t('on conflict upsert', fn () => $q("insert into \"{$p}_u\" (\"_id\", \"name\") values (?, ?) on conflict (\"_id\") do update set \"name\" = excluded.\"name\"", [2, 'bob2']));
t('PATCH statement', fn () => $q("patch into \"{$p}_u\" records {_id: 2, age: 26}"));
t('row after patch', fn () => $q("select * from \"{$p}_u\" where \"_id\" = 2"));
t('string _id', fn () => $q("insert into \"{$p}_s\" (\"_id\", \"v\") values (?, ?)", ['01J9ZQ0000000000000000000A', 1]));
t('uuid _id', fn () => $q("insert into \"{$p}_s\" (\"_id\", \"v\") values (cast(? as uuid), ?)", ['0192a1b2-0000-7000-8000-000000000001', 2]));
t('insert returning _id', fn () => $q("insert into \"{$p}_s\" (\"_id\", \"v\") values (?, ?) returning \"_id\"", ['k3', 3]));

echo "--- SQL features\n";
t('like / ilike', fn () => $q("select count(*) as n from \"{$p}_u\" where \"name\" like ? or \"name\" ilike ?", ['al%', 'BO%']));
t('in / between', fn () => $q("select \"_id\" from \"{$p}_u\" where \"_id\" in (?, ?) and \"age\" between ? and ?", [1, 2, 0, 100]));
t('group by having', fn () => $q("select \"active\", count(*) as n from \"{$p}_u\" group by \"active\" having count(*) > ?", [0]));
t('join', fn () => $q("select u.\"name\", e.\"_id\" as e from \"{$p}_u\" as u inner join \"{$p}_ev\" as e on e.\"_id\" = u.\"_id\""));
t('exists subquery', fn () => $q("select exists(select * from \"{$p}_u\" where \"_id\" = ?) as \"exists\"", [1]));
t('union all', fn () => $q("(select \"_id\" from \"{$p}_u\") union all (select \"_id\" from \"{$p}_ev\")"));
t('distinct / coalesce / case', fn () => $q("select distinct coalesce(\"age\", 0) as a, case when \"age\" > 30 then 'old' else 'young' end as c from \"{$p}_u\""));
t('lower/upper/length/concat', fn () => $q("select lower(\"name\") as l, upper(\"name\") as u, length(\"name\") as n, \"name\" || '!' as c from \"{$p}_u\""));
t('now() / current_timestamp', fn () => $q('select now() as n, current_timestamp as c'));
t('extract / date_trunc', fn () => $q("select extract(year from \"at\") as y, date_trunc('day', \"at\") as d from \"{$p}_ev\" where \"_id\" = 2"));
t('limit param', fn () => $q("select \"_id\" from \"{$p}_u\" order by \"_id\" limit ? offset ?", [1, 0]));
t('order nulls last', fn () => $q("select \"_id\" from \"{$p}_u\" order by \"age\" desc nulls last"));
t('select for update', fn () => $q("select * from \"{$p}_u\" where \"_id\" = 1 for update"));
t('json field access', fn () => $q("insert into \"{$p}_d\" records {_id: 1, meta: {source: 'yt', n: 5}}"));
t('nested field (meta).source', fn () => $q("select (\"meta\").\"source\" as s from \"{$p}_d\""));
t('nested ->> operator', fn () => $q("select \"meta\"->>'source' as s from \"{$p}_d\""));
t('RECORDS with param', fn () => $q("insert into \"{$p}_d\" records ?", ['{"_id": 2, "meta": {"source": "x"}}']));
t('json text param via parse', fn () => $q("insert into \"{$p}_d\" (\"_id\", \"meta\") values (?, parse_json(?))", [3, '{"source": "z"}']));

echo "--- schema / DDL\n";
t('create table', fn () => $q("create table \"{$p}_ddl\" (\"_id\" bigint primary key, \"name\" varchar(255))"));
t('alter table add column', fn () => $q("alter table \"{$p}_u\" add column \"x\" int"));
t('create index', fn () => $q("create index \"{$p}_i\" on \"{$p}_u\" (\"name\")"));
t('drop table', fn () => $q("drop table \"{$p}_s\""));
t('erase', fn () => $q("erase from \"{$p}_ev\" where \"_id\" = ?", [3]));
t('information_schema.tables', fn () => $q("select table_schema, table_name from information_schema.tables where table_name like ?", ["{$p}%"]));
t('information_schema.columns', fn () => $q("select column_name, data_type, is_nullable from information_schema.columns where table_name = ?", ["{$p}_u"]));
t('pg_class / pg_namespace', fn () => $q("select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relname like ?", ["{$p}%"]));
t('current_schema()', fn () => $q('select current_schema() as s, current_database() as d'));

echo "--- bitemporal\n";
t('_valid_from/_system_from', fn () => $q("select \"_id\", \"_valid_from\", \"_valid_to\", \"_system_from\" from \"{$p}_u\" where \"_id\" = 1"));
t('FOR ALL VALID_TIME history', fn () => $q("select \"_id\", \"name\", \"_valid_from\" from \"{$p}_u\" for all valid_time where \"_id\" = 1 order by \"_valid_from\""));
t('FOR SYSTEM_TIME AS OF', fn () => $q("select count(*) as n from \"{$p}_u\" for system_time as of timestamp '2020-01-01 00:00:00'"));
t('insert with _valid_from', fn () => $q("insert into \"{$p}_v\" (\"_id\", \"price\", \"_valid_from\") values (?, ?, cast(? as timestamp with time zone))", [1, 10, '2026-01-01 00:00:00+00']));
t('FOR VALID_TIME AS OF', fn () => $q("select count(*) as n from \"{$p}_v\" for valid_time as of timestamp '2025-06-01 00:00:00+00:00'"));
t('SETTING DEFAULT VALID_TIME', fn () => $q("setting default valid_time all select count(*) as n from \"{$p}_v\""));
