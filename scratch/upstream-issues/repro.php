<?php
// Runs the minimal reproductions of the four upstream issues on one XTDB server.
$port = $argv[1] ?? '5435';
$p = new PDO("pgsql:host=127.0.0.1;port={$port};dbname=xtdb", 'xtdb', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$s = bin2hex(random_bytes(2));
$version = $p->query('select version()')->fetchColumn();
$out = function (string $sql) use ($p) {
    try {
        $st = $p->query($sql);

        return $st->columnCount() ? json_encode($st->fetchAll(PDO::FETCH_NUM)) : 'ok';
    } catch (Throwable $e) {
        return 'ERROR '.preg_replace('/\s+/', ' ', substr(explode('DETAIL', $e->getMessage())[0], 0, 110));
    }
};
echo "== {$version}\n";
$out("INSERT INTO q1_$s RECORDS {_id: 1, tags: ['a', 'b']}");
printf("1 ANY precedence   'a' = ANY(tags) AND TRUE -> %s  | (…) AND TRUE -> %s  | ANY AND ANY -> %s\n",
    $out("SELECT _id FROM q1_$s WHERE 'a' = ANY(tags) AND TRUE"),
    $out("SELECT _id FROM q1_$s WHERE ('a' = ANY(tags)) AND TRUE"),
    $out("SELECT _id FROM q1_$s WHERE 'a' = ANY(tags) AND 'b' = ANY(tags)"));
$out("INSERT INTO q2_$s RECORDS {_id: 1, tags: ['a']}, {_id: 2, tags: NULL}");
printf("2 ANY(COALESCE)    -> %s\n", $out("SELECT _id FROM q2_$s WHERE 'a' = ANY(COALESCE(tags, []))"));
$created = $out("CREATE TABLE q3_$s (_id, batch)");
printf("3 max(valueless)   create -> %s | count -> %s | max -> %s | sum -> %s\n", $created, $out("SELECT count(batch) FROM q3_$s"), $out("SELECT max(batch) FROM q3_$s"), $out("SELECT sum(batch) FROM q3_$s"));
printf("4 E-string ''      -> %s\n", $out("SELECT 'it''s', E'it''s', E'it\\'s'"));
