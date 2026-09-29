<?php
$pdo = new PDO('pgsql:host=127.0.0.1;port='.(getenv('XTDB_PORT') ?: '5435').';dbname=xtdb', 'xtdb', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
$p = uniqid('u'); $id = 0;
foreach (['latin é' => 'café', 'vietnamese' => 'chào', 'cjk' => '漢字', 'emoji' => '🚀', 'mixed' => 'Xin chào 🚀', 'emoji at end' => 'ab🚀', 'emoji then text' => '🚀ab'] as $label => $value) {
    foreach (['insert values' => "insert into \"{$p}\" (\"_id\", \"s\") values (%d, ?)", 'update set' => "update \"{$p}\" set \"s\" = ? where \"_id\" = %d", 'where =' => "select count(*) from \"{$p}\" where \"s\" = ? and \"_id\" <> %d"] as $stmt => $sql) {
        $id++;
        try { $s = $pdo->prepare(sprintf($sql, $id)); $s->bindValue(1, $value); $s->execute(); echo "OK   [$label] $stmt\n"; }
        catch (Throwable $e) { echo "FAIL [$label] $stmt => ".substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 120)."\n"; }
    }
}
$rows = $pdo->query("select \"_id\", \"s\" from \"{$p}\" order by \"_id\"")->fetchAll(PDO::FETCH_KEY_PAIR);
echo "stored: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\n";
// Emoji through native prepared statement with explicit text cast
$n = new PDO('pgsql:host=127.0.0.1;port='.(getenv('XTDB_PORT') ?: '5435').';dbname=xtdb', 'xtdb', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try { $s = $n->prepare("insert into \"{$p}\" (\"_id\", \"s\") values (100, cast(? as varchar))"); $s->execute(['Xin chào 🚀']); echo "OK   native cast(? as varchar) insert: ".json_encode($n->query("select \"s\" from \"{$p}\" where \"_id\" = 100")->fetchColumn(), JSON_UNESCAPED_UNICODE)."\n"; }
catch (Throwable $e) { echo "FAIL native cast insert => ".substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160)."\n"; }
