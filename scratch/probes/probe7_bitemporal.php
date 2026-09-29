<?php
// Bitemporal SQL on XTDB 2.2: reads (valid/system time) and writes with a valid time.
$pdo = new PDO('pgsql:host=127.0.0.1;port='.(getenv('XTDB_PORT') ?: '5435').';dbname=xtdb', 'xtdb', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$t = '"bt_'.bin2hex(random_bytes(3)).'"';
function t(string $label, callable $fn) { try { $r = $fn(); echo "OK   $label".($r !== null ? ' => '.substr(json_encode($r, JSON_UNESCAPED_SLASHES), 0, 330) : '')."\n"; } catch (Throwable $e) { echo "FAIL $label => ".preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 190))."\n"; } }
$q = fn (string $sql) => ($s = $pdo->query($sql))->columnCount() ? $s->fetchAll() : null;
$rows = fn (string $clause = '', string $where = '') => $q("select _id, price, _valid_from, _valid_to from $t $clause $where order by _id, _valid_from");

echo "--- writes with a valid time\n";
t('insert with _valid_from/_valid_to', fn () => $q("insert into $t (_id, price, _valid_from, _valid_to) values (1, 10, TIMESTAMP '2026-01-01T00:00:00Z', TIMESTAMP '2026-07-01T00:00:00Z')"));
t('insert with _valid_from only', fn () => $q("insert into $t (_id, price, _valid_from) values (1, 12, TIMESTAMP '2026-07-01T00:00:00Z')"));
t('insert records with validity', fn () => $q("insert into $t records {_id: 2, price: 5, _valid_from: TIMESTAMP '2025-01-01T00:00:00Z'}"));
t('rows now (default = current valid time)', fn () => $rows());
t('FOR ALL VALID_TIME', fn () => $rows('for all valid_time'));
t('FOR VALID_TIME AS OF', fn () => $rows("for valid_time as of TIMESTAMP '2026-03-01T00:00:00Z'"));
t('FOR VALID_TIME FROM a TO b', fn () => $rows("for valid_time from TIMESTAMP '2026-06-01T00:00:00Z' to TIMESTAMP '2026-08-01T00:00:00Z'"));
t('FOR VALID_TIME BETWEEN a AND b', fn () => $rows("for valid_time between TIMESTAMP '2026-06-01T00:00:00Z' and TIMESTAMP '2026-08-01T00:00:00Z'"));
t('FOR VALID_TIME FROM a TO NULL', fn () => $rows("for valid_time from TIMESTAMP '2026-06-01T00:00:00Z' to NULL"));
t('FOR ALL SYSTEM_TIME', fn () => count($q("select _id, _system_from from $t for all system_time for all valid_time")));
t('system + valid AS OF combined', fn () => $rows("for system_time as of now() for valid_time as of TIMESTAMP '2026-03-01T00:00:00Z'"));
t('valid then system order', fn () => $rows("for valid_time as of TIMESTAMP '2026-03-01T00:00:00Z' for system_time as of now()"));
t('clause + alias', fn () => $q("select x._id from $t for all valid_time as x where x._id = 2"));
t('SETTING DEFAULT VALID_TIME AS OF', fn () => $q("setting default valid_time as of TIMESTAMP '2026-03-01T00:00:00Z' select _id, price from $t order by _id"));
t('select * includes validity cols?', fn () => array_keys($q("select * from $t where _id = 2")[0] ?? []));

echo "--- update / delete for a portion of valid time\n";
t('UPDATE FOR PORTION OF VALID_TIME', fn () => $q("update $t for portion of valid_time from TIMESTAMP '2026-03-01T00:00:00Z' to TIMESTAMP '2026-04-01T00:00:00Z' set price = 11 where _id = 1"));
t('history after portion update', fn () => $rows('for all valid_time', 'where _id = 1'));
t('UPDATE portion FROM a (open end)', fn () => $q("update $t for portion of valid_time from TIMESTAMP '2027-01-01T00:00:00Z' set price = 15 where _id = 1"));
t('UPDATE portion FROM a TO NULL', fn () => $q("update $t for portion of valid_time from TIMESTAMP '2027-06-01T00:00:00Z' to NULL set price = 16 where _id = 1"));
t('history after open-ended updates', fn () => $rows('for all valid_time', 'where _id = 1'));
t('UPDATE FOR ALL VALID_TIME', fn () => $q("update $t for all valid_time set price = price + 100 where _id = 2"));
t('UPDATE with alias + portion', fn () => $q("update $t for portion of valid_time from TIMESTAMP '2025-02-01T00:00:00Z' to TIMESTAMP '2025-03-01T00:00:00Z' as x set price = 1 where x._id = 2"));
t('DELETE FOR PORTION OF VALID_TIME', fn () => $q("delete from $t for portion of valid_time from TIMESTAMP '2025-06-01T00:00:00Z' to TIMESTAMP '2025-07-01T00:00:00Z' where _id = 2"));
t('DELETE FOR ALL VALID_TIME', fn () => $q("delete from $t for all valid_time where _id = 99"));
t('history of 2', fn () => $rows('for all valid_time', 'where _id = 2'));
t('plain DELETE (ends validity now)', fn () => $q("delete from $t where _id = 2"));
t('2 now', fn () => $rows('', 'where _id = 2'));
t('2 history keeps rows', fn () => count($rows('for all valid_time', 'where _id = 2')));
t('ERASE with where', fn () => $q("erase from $t where _id = 2"));
t('2 after erase, all valid + system time', fn () => count($q("select _id from $t for all system_time for all valid_time where _id = 2")));

echo "--- patch / update with validity columns\n";
t('PATCH with _valid_from', fn () => $q("patch into $t records {_id: 1, note: 'n', _valid_from: TIMESTAMP '2028-01-01T00:00:00Z'}"));
t('history of 1 (final)', fn () => $rows('for all valid_time', 'where _id = 1'));
t('period predicates: CONTAINS', fn () => $q("select _id from $t for all valid_time where _valid_time contains TIMESTAMP '2026-03-15T00:00:00Z' order by _id"));
t('period OVERLAPS', fn () => count($q("select _id from $t for all valid_time where _valid_time overlaps period(TIMESTAMP '2026-03-01T00:00:00Z', TIMESTAMP '2026-05-01T00:00:00Z')")));
