<?php
declare(strict_types=1);

/**
 * Short status report for the server, used by deploy/ionos.sh.
 *
 *   php bin/healthcheck.php
 *
 * Prints nothing sensitive (no host names, user names or paths), because its
 * output appears in the public GitHub Actions log.
 */

use Landlords\Storage\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$lines = [];
$ok = true;

$extensions = array_map(
    static fn(string $e) => $e . (extension_loaded($e) ? ' yes' : ' MISSING'),
    ['curl', 'pdo_mysql', 'json', 'mbstring', 'openssl']
);
$lines[] = 'PHP extensions: ' . implode(', ', $extensions);

try {
    $config = landlords_config();
} catch (Throwable $e) {
    echo implode("\n", $lines), "\nconfig.php: missing\n";
    exit(1);
}

try {
    $pdo = Database::connect($config['db']);
    $lines[] = 'Database: connected (' . Database::version($pdo) . ')';
} catch (PDOException $e) {
    echo implode("\n", $lines), "\nDatabase: connection failed (" . Database::describeError($e) . ")\n";
    exit(1);
}

try {
    $by = $pdo->query("SELECT kind, stage, COUNT(*) AS n FROM licences WHERE removed_at IS NULL GROUP BY kind, stage ORDER BY kind, stage")->fetchAll();
    $lines[] = 'Stored: ' . ($by ? implode(', ', array_map(fn($r) => "{$r['kind']} {$r['stage']}s {$r['n']}", $by)) : 'nothing yet')
        . '; student schemes ' . (int) $pdo->query('SELECT COUNT(*) FROM student_schemes WHERE removed_at IS NULL')->fetchColumn();
    $reg = $pdo->query("SELECT SUM(max_occupants IS NOT NULL) AS hmo, SUM(property_type IS NOT NULL) AS sel FROM licences WHERE removed_at IS NULL AND stage = 'licence'")->fetch();
    $lines[] = sprintf('Register details: %d HMOs with occupancy limits, %d selective licences with a property type', (int) $reg['hmo'], (int) $reg['sel']);
    $run = $pdo->query('SELECT started_at, status, fetched, inserted, updated, removed FROM sync_runs ORDER BY id DESC LIMIT 1')->fetch();
    $lines[] = $run
        ? sprintf('Last sync: %s UTC, %s (fetched %s, new %s, changed %s, removed %s)',
            $run['started_at'], $run['status'], $run['fetched'] ?? '-', $run['inserted'] ?? '-',
            $run['updated'] ?? '-', $run['removed'] ?? '-')
        : 'Last sync: never';
    if ($run && $run['status'] !== 'ok') {
        $ok = false;
    }
    $since = gmdate('Y-m-d H:i:s', time() - 4 * 86400);
    $recent = $pdo->prepare('SELECT started_at, status FROM sync_runs WHERE started_at >= ? ORDER BY id');
    $recent->execute([$since]);
    $runs = array_map(fn($r) => substr($r['started_at'], 5, 11) . ' ' . $r['status'], $recent->fetchAll());
    $lines[] = 'Sync runs in the last 4 days (UTC): ' . ($runs ? implode('; ', $runs) : 'none');
    $failed = $pdo->query("SELECT started_at, message FROM sync_runs WHERE status = 'failed' ORDER BY id DESC LIMIT 1")->fetch();
    if ($failed) {
        $lines[] = 'Last failed sync (' . $failed['started_at'] . ' UTC): ' . str_replace("\n", ' / ', (string) $failed['message']);
    }
} catch (PDOException $e) {
    $lines[] = 'Tables: not created yet (run the setup task)';
    $ok = false;
}

try {
    $c = (new \Landlords\Subscribers\SubscriberStore($pdo))->counts();
    $open = (new \Landlords\Storage\Settings($pdo))->signupOpen();
    $lines[] = sprintf('Weekly email: sign-up %s; %d subscribers, %d awaiting confirmation',
        $open ? 'open' : 'closed', $c['active'], $c['pending']);
    $d = $pdo->query('SELECT created_at, status, items FROM digests ORDER BY id DESC LIMIT 1')->fetch();
    $lines[] = $d ? sprintf('Last digest: %s UTC, %s, %d changes', $d['created_at'], $d['status'], $d['items'])
                  : 'Last digest: none yet';
    $lines[] = 'Mail settings: ' . (empty($config['mail']['password']) ? 'not set (add the mail password secret and run configure)' : 'set');
} catch (PDOException $e) {
    $lines[] = 'Weekly email: tables not created yet';
}

$meta = rtrim($config['paths']['public_data'], '/') . '/meta.json';
if (is_file($meta)) {
    $m = json_decode((string) file_get_contents($meta), true);
    $lines[] = sprintf('Map data: %d pins and %d student schemes, refreshed %s', $m['licences'] ?? 0, $m['students'] ?? 0, $m['synced_at'] ?? '?');
} else {
    $lines[] = 'Map data: not written yet';
}

echo implode("\n", $lines), "\n";
exit($ok ? 0 : 1);
