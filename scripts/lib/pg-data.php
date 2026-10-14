<?php

/**
 * PostgreSQL data snapshot without pg_dump/psql (shared hosting often ships
 * client tools older than the server, which pg_dump refuses to work with).
 *
 * Connects with the credentials OJS itself reads from config.inc.php. The
 * snapshot holds the rows of every table and the sequence values; the schema
 * is left to OJS (take a new snapshot after an OJS upgrade). Restoring needs
 * the database user to own the tables (it drops and recreates foreign keys).
 *
 *   php pg-data.php dump    OJS_DIR FILE.gz
 *   php pg-data.php restore OJS_DIR FILE.gz
 *   php pg-data.php exec    OJS_DIR "SQL"
 *
 * File format (gzip, one JSON value per line):
 *   {"format":"ojs-pgdata-1","sequences":{...}}
 *   {"table":"name","columns":[...]}  followed by one JSON array per row
 */

if ($argc < 4 || !in_array($argv[1], ['dump', 'restore', 'exec'], true)) {
    fwrite(STDERR, "Usage: php pg-data.php dump|restore OJS_DIR FILE.gz | exec OJS_DIR SQL\n");
    exit(1);
}
[, $command, $ojsDir, $arg] = $argv;

require_once $ojsDir . '/lib/pkp/classes/config/ConfigParser.php';
$config = \PKP\config\ConfigParser::readConfig($ojsDir . '/config.inc.php');
$db = $config['database'] ?? [];
$host = (string) ($db['host'] ?? 'localhost');
$port = $db['port'] ?? null;
if (!$port && preg_match('/^(.*):(\d+)$/', $host, $m)) {
    [$host, $port] = [$m[1], $m[2]];
}
$dsn = 'pgsql:host=' . ($host ?: 'localhost') . ';port=' . ($port ?: 5432) . ';dbname=' . $db['name'];
$pdo = new PDO($dsn, (string) $db['username'], (string) $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
]);
$pdo->exec("SET client_encoding TO 'UTF8'");
$pdo->exec('SET client_min_messages TO warning');

function q(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

/** Tables of the current schema. */
function tablesInOrder(PDO $pdo): array
{
    return $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
}

function encodeValue($v)
{
    if (is_resource($v)) { // bytea
        return ['$b' => base64_encode(stream_get_contents($v))];
    }
    return $v;
}

if ($command === 'exec') {
    echo $pdo->exec($arg), "\n";
    exit(0);
}

if ($command === 'dump') {
    $out = gzopen($arg, 'wb6');
    $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $sequences = [];
    foreach ($pdo->query("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind = 'S' AND n.nspname = current_schema()")->fetchAll(PDO::FETCH_COLUMN) as $seq) {
        [$last, $called] = $pdo->query('SELECT last_value, is_called FROM ' . q($seq))->fetch();
        $sequences[$seq] = [(int) $last, (bool) $called];
    }
    gzwrite($out, json_encode(['format' => 'ojs-pgdata-1', 'sequences' => $sequences]) . "\n");
    $total = 0;
    foreach (tablesInOrder($pdo) as $table) {
        $columns = $pdo->query("SELECT attname FROM pg_attribute WHERE attrelid = " . $pdo->quote(q($table)) . "::regclass AND attnum > 0 AND NOT attisdropped ORDER BY attnum")->fetchAll(PDO::FETCH_COLUMN);
        gzwrite($out, json_encode(['table' => $table, 'columns' => $columns], JSON_UNESCAPED_UNICODE) . "\n");
        $pdo->exec('DECLARE snap NO SCROLL CURSOR FOR SELECT ' . implode(', ', array_map('q', $columns)) . ' FROM ' . q($table) . ' ORDER BY 1');
        do {
            $rows = $pdo->query('FETCH 2000 FROM snap')->fetchAll();
            foreach ($rows as $row) {
                gzwrite($out, json_encode(array_map('encodeValue', $row), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
            $total += count($rows);
        } while ($rows);
        $pdo->exec('CLOSE snap');
    }
    $pdo->exec('COMMIT');
    gzclose($out);
    echo "$total rows\n";
    exit(0);
}

// restore
$in = gzopen($arg, 'rb');
$header = json_decode((string) gzgets($in), true);
if (($header['format'] ?? '') !== 'ojs-pgdata-1') {
    fwrite(STDERR, "Not an ojs-pgdata snapshot: $arg\n");
    exit(1);
}
$existing = array_flip($pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema()")->fetchAll(PDO::FETCH_COLUMN));

// first pass: which tables does the snapshot hold
$snapshotTables = [];
while (($line = gzgets($in)) !== false) {
    if (str_starts_with($line, '{"table":')) {
        $snapshotTables[] = json_decode($line, true)['table'];
    }
}
gzrewind($in);
gzgets($in);

$pdo->beginTransaction();
// The OJS schema has circular foreign keys (submissions <-> publications), so
// rows cannot be loaded table by table in a valid order. The foreign keys are
// dropped for the load and recreated from their own definitions afterwards
// (which also re-checks them), all within one transaction.
$foreignKeys = $pdo->query("SELECT c.relname, k.conname, pg_get_constraintdef(k.oid) FROM pg_constraint k
    JOIN pg_class c ON c.oid = k.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE k.contype = 'f' AND n.nspname = current_schema()")->fetchAll();
foreach ($foreignKeys as [$fkTable, $fkName]) {
    $pdo->exec('ALTER TABLE ' . q($fkTable) . ' DROP CONSTRAINT ' . q($fkName));
}
$truncate = array_values(array_filter($snapshotTables, fn ($t) => isset($existing[$t])));
if ($truncate) {
    $pdo->exec('TRUNCATE ' . implode(', ', array_map('q', $truncate)));
}

$table = null;
$columns = [];
$batch = [];
$total = 0;
$flush = function () use ($pdo, &$table, &$columns, &$batch, &$total) {
    if (!$batch || $table === null) {
        $batch = [];
        return;
    }
    $row = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $stmt = $pdo->prepare('INSERT INTO ' . q($table) . ' (' . implode(', ', array_map('q', $columns)) . ') VALUES ' . implode(', ', array_fill(0, count($batch), $row)));
    $i = 1;
    foreach ($batch as $values) {
        foreach ($values as $v) {
            if (is_array($v) && isset($v['$b'])) {
                $stmt->bindValue($i++, base64_decode($v['$b']), PDO::PARAM_LOB);
            } elseif (is_bool($v)) {
                $stmt->bindValue($i++, $v, PDO::PARAM_BOOL);
            } elseif (is_int($v)) {
                $stmt->bindValue($i++, $v, PDO::PARAM_INT);
            } elseif ($v === null) {
                $stmt->bindValue($i++, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($i++, (string) $v, PDO::PARAM_STR);
            }
        }
    }
    $stmt->execute();
    $total += count($batch);
    $batch = [];
};
$skipped = [];
while (($line = gzgets($in)) !== false) {
    $data = json_decode($line, true);
    if (isset($data['table']) && isset($data['columns'])) {
        $flush();
        $table = isset($existing[$data['table']]) ? $data['table'] : null;
        if ($table === null) {
            $skipped[] = $data['table'];
        }
        $columns = $data['columns'];
        continue;
    }
    $batch[] = $data;
    if (count($batch) * max(1, count($columns)) >= 20000) {
        $flush();
    }
}
$flush();
gzclose($in);

foreach ($foreignKeys as [$fkTable, $fkName, $fkDefinition]) {
    $pdo->exec('ALTER TABLE ' . q($fkTable) . ' ADD CONSTRAINT ' . q($fkName) . ' ' . $fkDefinition);
}
foreach ($header['sequences'] as $seq => [$value, $called]) {
    if ($pdo->query("SELECT to_regclass(" . $pdo->quote(q($seq)) . ")")->fetchColumn()) {
        $pdo->query('SELECT setval(' . $pdo->quote(q($seq)) . ', ' . max(1, (int) $value) . ', ' . ($called ? 'true' : 'false') . ')');
    }
}
$pdo->commit();
echo "$total rows" . ($skipped ? '; tables missing in the database, skipped: ' . implode(', ', $skipped) : '') . "\n";
