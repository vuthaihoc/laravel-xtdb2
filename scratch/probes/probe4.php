<?php
foreach (['emulate' => true, 'native' => false] as $mode => $emulate) {
    $pdo = new PDO('pgsql:host=127.0.0.1;port='.(getenv('XTDB_PORT') ?: '5435').';dbname=xtdb', 'xtdb', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => $emulate, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    echo "--- $mode\n";
    foreach (['ascii' => 'plain', 'latin é' => 'café', 'vietnamese' => 'chào', 'cjk' => '漢字', 'emoji' => '🚀', 'mixed' => 'Xin chào 🚀'] as $label => $value) {
        try {
            $s = $pdo->prepare('select ? as v'); $s->bindValue(1, $value, PDO::PARAM_STR); $s->execute();
            $got = $s->fetchColumn();
            echo "OK   select param [$label] ".($got === $value ? 'round-trips' : 'CHANGED to '.json_encode($got))."\n";
        } catch (Throwable $e) { echo "FAIL select param [$label] => ".substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 150)."\n"; }
    }
}
$pdo = new PDO('pgsql:host=127.0.0.1;port='.(getenv('XTDB_PORT') ?: '5435').';dbname=xtdb', 'xtdb', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo "--- literal forms\n";
foreach (["'chào'", "'🚀'", "U&'ch\\00E0o'", "U&'\\+01F680'", "E'ch\\u00E0o'"] as $lit) {
    try { $got = $pdo->query("select $lit as v")->fetchColumn(); echo "OK   $lit => ".json_encode($got, JSON_UNESCAPED_UNICODE)."\n"; }
    catch (Throwable $e) { echo "FAIL $lit => ".substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 150)."\n"; }
}
echo "client_encoding: ".json_encode($pdo->query('show client_encoding')->fetchAll())."\n";
