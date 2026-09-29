<?php
$port = $argv[1];
$pdo = new PDO("pgsql:host=127.0.0.1;port=$port;dbname=xtdb", 'xtdb', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function t(string $label, callable $fn) { try { $r = $fn(); echo "OK   $label".($r !== null ? ' => '.substr(json_encode($r, JSON_UNESCAPED_SLASHES), 0, 200) : '')."\n"; } catch (Throwable $e) { echo "FAIL $label => ".preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 170))."\n"; } }
$q = fn ($sql) => ($s = $pdo->query($sql))->columnCount() ? $s->fetchAll() : null;
$p = uniqid('d');
t('create table', fn () => $q("create table \"{$p}_a\" (\"_id\" bigint, \"name\" varchar)"));
t('create table if not exists', fn () => $q("create table if not exists \"{$p}_b\" (\"_id\" bigint)"));
t('select from created empty table', fn () => $q("select * from \"{$p}_a\""));
t('insert null column', fn () => $q("insert into \"{$p}_c\" (\"_id\", \"deleted_at\") values (1, NULL)"));
t('where on null-only column', fn () => $q("select \"_id\" from \"{$p}_c\" where \"deleted_at\" is null"));
t('where on never-written column', fn () => $q("select \"_id\" from \"{$p}_c\" where \"other\" is null"));
t('select * never-written table', fn () => $q("select * from \"{$p}_none\""));
t('insert typed null (cast)', fn () => $q("insert into \"{$p}_c\" (\"_id\", \"at\") values (2, cast(NULL as timestamp with time zone))"));
t('where typed-null column', fn () => $q("select \"_id\" from \"{$p}_c\" where \"at\" is null order by \"_id\""));
t('erase all then select', function () use ($q, $p) { $q("erase from \"{$p}_c\" where true"); return $q("select \"_id\" from \"{$p}_c\" where \"deleted_at\" is null"); });
t('alter table', fn () => $q("alter table \"{$p}_c\" add column \"z\" int"));
t('drop table', fn () => $q("drop table \"{$p}_c\""));
t('information_schema after erase', fn () => $q("select table_name from information_schema.tables where table_name like '{$p}%' order by 1"));
t('columns meta', fn () => $q("select column_name, data_type from information_schema.columns where table_name = '{$p}_c' and column_name not in ('_valid_from','_valid_to','_system_from','_system_to') order by 1"));
t('SET search_path', fn () => $q("set search_path to public"));
t('SHOW / settings', fn () => $q("show transaction isolation level"));
t('insert returning', fn () => $q("insert into \"{$p}_r\" (\"_id\", \"v\") values (1, 2) returning \"_id\""));
t('delete returning', fn () => $q("delete from \"{$p}_r\" where \"_id\" = 1 returning \"_id\""));
