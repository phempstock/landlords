<?php
declare(strict_types=1);

/**
 * LANDLORDS test suite. No framework needed:
 *
 *   php tests/run.php
 *
 * Uses an in-memory SQLite database (or the database in LANDLORDS_TEST_DSN) and a
 * fake council server, so it needs no network.
 */

use Landlords\Council\ArcGisClient;
use Landlords\Council\Layers;
use Landlords\Council\Records;
use Landlords\Digest\ChangeReport;
use Landlords\Digest\DigestBuilder;
use Landlords\Digest\DigestSender;
use Landlords\Export\GeoJsonExporter;
use Landlords\Mail\LogMailer;
use Landlords\Registers\RegisterEnricher;
use Landlords\Registers\XlsReader;
use Landlords\Registers\XlsxReader;
use Landlords\Storage\Database;
use Landlords\Storage\Settings;
use Landlords\Storage\TrackedStore;
use Landlords\Subscribers\SignupService;
use Landlords\Subscribers\SubscriberStore;
use Landlords\Support\Address;
use Landlords\Support\Geo;
use Landlords\SyncJob;
use Landlords\Tests\FakeHttpClient;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/FakeHttpClient.php';

$failures = 0;
$passes = 0;

function check(bool $condition, string $message): void
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
    } else {
        $failures++;
        echo "  FAIL: {$message}\n";
    }
}

function test(string $name, callable $fn): void
{
    echo "- {$name}\n";
    try {
        $fn();
    } catch (Throwable $e) {
        check(false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

function ms(string $date): int
{
    return (int) (strtotime($date . ' 00:00:00 UTC') * 1000);
}

function readJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true);
}

/** A licence point as the council's server returns it (values from The Park, 17 Nov 2026). */
function lic(int $oid, string $ref, string $scheme, string $address, ?string $issued, ?string $expiry, float $x, float $y): array
{
    return ['attributes' => [
        'OBJECTID' => $oid, 'REF' => $ref, 'LICENSING_SCHEME' => $scheme, 'ADDRESS' => $address,
        'DATE_ISSUED' => $issued ? ms($issued) : null, 'EXPIRY_DATE' => $expiry ? ms($expiry) : null,
        'WEBSITE' => 'https://www.nottinghamcity.gov.uk/hmo',
    ], 'geometry' => ['x' => $x, 'y' => $y]];
}

function app(int $oid, string $ref, string $scheme, string $address, ?string $received, float $x, float $y): array
{
    return ['attributes' => [
        'OBJECTID' => $oid, 'REF' => $ref, 'LICENSING_SCHEME' => $scheme, 'ADDRESS' => $address,
        'APP_DATE' => $received ? ms($received) : null, 'WEBSITE' => 'https://www.nottinghamcity.gov.uk/hmo',
    ], 'geometry' => ['x' => $x, 'y' => $y]];
}

function pbsa(string $id, string $name, string $address, int $beds, string $year, ?string $planningRef, string $provider, float $x, float $y): array
{
    $d = 0.0002;
    return ['attributes' => [
        'OBJECTID' => crc32($id) % 100000, 'SCHEME_UNIQUE_ID' => $id, 'NAME' => $name, 'ADDRESS' => $address, 'POSTCODE' => null,
        'BEDSPACES' => $beds, 'STUDIOS' => 0, 'CLUSTERS' => $beds, 'TYPE_OF_SCHEME' => 'Cluster flats',
        'ACADEMIC_YEAR_COMPLETED_OR_PRO' => $year, 'PLANNING_APPLICATION_REFERENCE' => $planningRef, 'PROVIDER' => $provider,
    ], 'geometry' => ['rings' => [[[$x - $d, $y - $d], [$x - $d, $y + $d], [$x + $d, $y + $d], [$x + $d, $y - $d], [$x - $d, $y - $d]]]]];
}

/** The council's data on the first day. */
function dayOne(FakeHttpClient $http): void
{
    $http->layers[23] = [
        lic(59355, 'FS-Case-486116703', 'Mandatory', '230 Derby Road, Nottingham, NG7 1NX', '2023-09-29', '2028-03-01', -1.16841, 52.9544),
        lic(59786, 'FS-Case-427207101', 'Mandatory', '9 Harrington Drive, Nottingham, NG7 1JQ', '2021-11-02', '2026-11-05', -1.17209, 52.9522),
    ];
    $http->layers[6] = [
        lic(55777, 'FS-Case-525075331', 'Additional', 'Flat 2, 1 Balfour Road, Nottingham, NG7 1NY', '2024-01-08', '2028-06-01', -1.16909, 52.95423),
        lic(56246, 'FS-Case-420002784', 'Additional', '5 Westcliffe Court, 45-47 Lenton Road, Nottingham, NG7 1DW', '2022-09-30', '2027-05-19', -1.16591, 52.94762),
        lic(58247, 'FS-Case-418639716', 'Additional', '4 Westcliffe Court, 45-47 Lenton Road, Nottingham, NG7 1DW', '2022-09-30', '2027-05-19', -1.16591, 52.94762),
    ];
    $http->layers[16] = [
        lic(70001, 'FS-Case-844980230', 'Selective', 'Apartment 2, 190 Derby Road, Nottingham, NG7 1NF', '2026-06-11', '2031-06-10', -1.1630, 52.9545),
        lic(70002, 'FS-Case-700000001', 'Selective', '12 Lenton Road, Nottingham, NG7 1DU', '2024-01-10', '2029-01-09', -1.1601, 52.9490),
    ];
    $http->layers[25] = [app(159365, 'FS-Case-761356625', 'Mandatory', '16 Barrack Lane, Nottingham, NG7 1AN', '2025-10-30', -1.1684, 52.95323)];
    $http->layers[26] = [app(169950, 'FS-Case-773392898', 'Additional', '78 Harlaxton Drive, Nottingham, NG7 1JB', '2025-12-14', -1.16853, 52.95022)];
    $http->layers[27] = [];
    $http->layers[21] = [pbsa('PBSA-0101', '8 Clinton Terrace', '8 Clinton Terrace, Derby Road, Nottingham', 26, 'Archive', '22/00587/PFUL3', 'Clinton View Ltd', -1.1650, 52.9540)];
}

/** A week later: renewals, grants, endings and new applications. */
function dayEight(FakeHttpClient $http): void
{
    dayOne($http);
    $http->layers[23] = [
        // 230 Derby Road renewed: a new licence with a new reference replaces the old one.
        lic(60001, 'FS-Case-900000001', 'Mandatory', '230 Derby Road, Nottingham, NG7 1NX', '2026-11-05', '2031-11-04', -1.16841, 52.9544),
        // 16 Barrack Lane granted (its application leaves the pending list).
        lic(60002, 'FS-Case-900000002', 'Mandatory', '16 Barrack Lane, Nottingham, NG7 1AN', '2026-11-06', '2031-11-05', -1.1684, 52.95323),
        // 9 Harrington Drive expired on 5 Nov and is gone.
    ];
    $http->layers[25] = [];
    // 78 Harlaxton Drive withdrawn; a new application at 3 Example Street.
    $http->layers[26] = [app(170001, 'FS-Case-900000003', 'Additional', '3 Example Street, Nottingham, NG7 1AA', '2026-11-04', -1.1610, 52.9500)];
    // A renewal application for 12 Lenton Road, which already holds a selective licence.
    $http->layers[27] = [app(170002, 'FS-Case-900000004', 'Selective', '12 Lenton Road, Nottingham, NG7 1DU', '2026-11-03', -1.1601, 52.9490)];
    // The selective licence at 190 Derby Road had its expiry corrected.
    $http->layers[16][0]['attributes']['EXPIRY_DATE'] = ms('2031-06-11');
    // Student schemes: more beds at Clinton Terrace, and a new scheme.
    $http->layers[21] = [
        pbsa('PBSA-0101', '8 Clinton Terrace', '8 Clinton Terrace, Derby Road, Nottingham', 30, 'Archive', '22/00587/PFUL3', 'Clinton View Ltd', -1.1650, 52.9540),
        pbsa('PBSA-0202', 'Site of former Ropewalk pub', '107-111 Derby Road', 59, '2027/28', '24/01952/PFUL3', 'Unknown', -1.1600, 52.9530),
    ];
}

function testConnection(): PDO
{
    return Database::connect([
        'dsn'      => getenv('LANDLORDS_TEST_DSN') ?: 'sqlite::memory:',
        'user'     => getenv('LANDLORDS_TEST_USER') ?: null,
        'password' => getenv('LANDLORDS_TEST_PASSWORD') ?: null,
    ]);
}

function harness(float $bufferMetres = 50, bool $registers = false): array
{
    $pdo = testConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (getenv('LANDLORDS_TEST_DSN')) {
        // Shared test database: start every test from empty tables.
        foreach (['licences', 'student_schemes', 'events', 'sync_runs', 'settings', 'subscribers', 'signup_attempts', 'digests', 'digest_sends'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
    $migrations = glob(dirname(__DIR__) . '/migrations/' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '/*.sql');
    sort($migrations);
    foreach ($migrations as $file) {
        Database::migrate($pdo, $file);
    }
    $dir = sys_get_temp_dir() . '/landlords-test-' . bin2hex(random_bytes(4));
    mkdir($dir . '/public', 0755, true);
    $http = new FakeHttpClient();
    $council = new ArcGisClient($http,
        'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Housing_and_Property/MapServer',
        'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Local_Plan_2020/MapServer/49',
        'https://maps164.nottinghamcity.gov.uk/server/rest/services/Utilities/Geometry/GeometryServer');
    $licences = new TrackedStore($pdo, 'licences', 'lkey', 'licence', Records::LICENCE_TRACKED);
    $students = new TrackedStore($pdo, 'student_schemes', 'scheme_id', 'student', Records::STUDENT_TRACKED, Records::STUDENT_QUIET);
    $enricher = null;
    if ($registers) {
        $http->raw['https://council.test/hmo'] = '<p><a href="/media/ydgakyzh/hmo-public-register-02-11-26.xls">HMO Public Register</a></p>';
        $http->raw['https://council.test/media/ydgakyzh/hmo-public-register-02-11-26.xls'] = (string) file_get_contents(__DIR__ . '/fixtures/hmo-register.xls');
        $http->raw['https://council.test/selective'] = '<a href="https://council.test/media/hlxna421/5-sl-redacted-register-end-of-july-2026.xlsx">SL Redacted Public Register</a>';
        $http->raw['https://council.test/media/hlxna421/5-sl-redacted-register-end-of-july-2026.xlsx'] = (string) file_get_contents(__DIR__ . '/fixtures/selective-register.xlsx');
        $enricher = new RegisterEnricher($http, $pdo, $dir . '/cache', 'https://council.test/hmo', 'https://council.test/selective');
    }
    $exporter = new GeoJsonExporter($dir . '/public', 'https://nimby.parknews.co.uk');
    $job = new SyncJob($council, $licences, $students, $exporter, $pdo, 'The Park', $dir . '/cache', 0.8, $bufferMetres, $enricher);
    return compact('pdo', 'dir', 'http', 'licences', 'students', 'job');
}

/** The licences.geojson features by address. */
function pins(string $dir): array
{
    $out = [];
    foreach (readJson($dir . '/public/licences.geojson')['features'] as $f) {
        $out[$f['properties']['address'] . ' / ' . $f['properties']['layer']] = $f['properties'];
    }
    return $out;
}

// ---------------------------------------------------------------- data

test('Address keys and record normalisation', function () {
    check(Address::key('Flat 2, 1 Balfour Road, Nottingham, NG7 1NY') === 'flat21balfourroad|NG71NY', 'map address key (' . Address::key('Flat 2, 1 Balfour Road, Nottingham, NG7 1NY') . ')');
    check(Address::key(['Flat 2', '1 Balfour Road', 'Nottingham', 'NG7 1NY']) === Address::key('Flat 2, 1 Balfour Road, Nottingham, NG7 1NY'), 'register lines give the same key');
    check(Address::key('11 - 13 Victoria Street, Nottingham, NG1 2EW') === Address::key('11-13 Victoria Street, NG12EW'), 'spacing and postcode style ignored');
    check(Address::postcode('230 Derby Road, Nottingham, ng7 1nx') === 'NG7 1NX', 'postcode found and tidied');
    check(Address::tidy('1 Clumber Road East, Nottingham, Nottingham City, NG7 1BD') === '1 Clumber Road East, NG7 1BD', 'tidy address');

    $layer = Layers::licensing()[23];
    $r = Records::licence(lic(1, ' FS-Case-1 ', 'Mandatory', "230  Derby Road,\x16 Nottingham, NG7 1NX", '2023-09-29', '2028-03-01', -1.168411234, 52.9544), $layer);
    check($r['ref'] === 'FS-Case-1' && $r['address'] === '230 Derby Road, Nottingham, NG7 1NX', 'text cleaned (' . json_encode($r['address']) . ')');
    check($r['date_issued'] === '2023-09-29' && $r['expiry_date'] === '2028-03-01' && $r['app_date'] === null, 'dates converted');
    check($r['lng'] === -1.168411 && $r['stage'] === 'licence' && $r['kind'] === 'hmo' && $r['scheme'] === 'Mandatory', 'location and layer recorded');
    check(str_starts_with($r['lkey'], 'hmo-mandatory-licence:FS-Case-1:'), 'key carries layer and reference (' . $r['lkey'] . ')');
    $same = Records::licence(lic(2, 'FS-Case-1', 'Mandatory', '9 Other Road, NG7 1AA', null, null, 0, 0), $layer);
    check($same['lkey'] !== $r['lkey'], 'a reused reference at another address is a separate record');
    check(Records::licence(lic(3, '', 'Mandatory', 'x', null, null, 0, 0), $layer) === null, 'no reference: skipped');
    check(Layers::label('hmo', 'Additional', 'application') === 'HMO licence application (additional)'
        && Layers::label('selective', 'Selective', 'licence') === 'Selective licence', 'labels');

    $s = Records::student(pbsa('X1', 'Name', '1 Road', 12, '2021/22', '20/01401/PFUL3', 'Private owner', -1.16, 52.95));
    check($s['bedspaces'] === 12 && $s['planning_ref'] === '20/01401/PFUL3', 'student scheme fields');
    check(abs($s['lat'] - 52.95) < 0.00001 && abs($s['lng'] + 1.16) < 0.00001, 'student scheme centre (' . $s['lat'] . ',' . $s['lng'] . ')');
    check(json_decode($s['geometry'], true)['type'] === 'Polygon', 'outline kept as GeoJSON');
});

test('First run: history, map files and inside flags', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir] = harness();
    dayOne($http);
    $summary = $job->run(false, '2026-11-10 06:00:00');
    check($summary['status'] === 'ok' && $summary['inserted'] === 10, 'stored 9 licences and applications and 1 scheme (' . json_encode($summary['log']) . ')');
    check((int) $pdo->query('SELECT COUNT(*) FROM licences WHERE backfilled = 1')->fetchColumn() === 9, 'all marked as history');

    $posts = array_values(array_filter($http->requests, fn($r) => $r[0] === 'POST' && str_contains($r[1], '/query')));
    check(count($posts) === 7, 'one query per layer (' . count($posts) . ')');
    check($posts[0][2]['distance'] === '50' && $posts[0][2]['geometryType'] === 'esriGeometryPolygon' && $posts[0][2]['outSR'] === '4326', 'queried with the Park polygon and a 50 m margin');

    $pins = pins($dir);
    check(count($pins) === 9, '9 pins');
    $p = $pins['230 Derby Road, Nottingham, NG7 1NX / hmo'];
    check($p['label'] === 'HMO licence (mandatory)' && $p['issued'] === '2023-09-29' && $p['expires'] === '2028-03-01', 'licence pin properties');
    check(!isset($p['first_seen']) && $p['inside'] === true && strlen($p['id']) === 10, 'history is not new; inside The Park; short id');
    check($p['url'] === 'https://www.nottinghamcity.gov.uk/hmo', 'links to the council’s scheme page');
    check($pins['9 Harrington Drive, Nottingham, NG7 1JQ / hmo']['inside'] === false, 'margin property flagged');
    check($pins['16 Barrack Lane, Nottingham, NG7 1AN / hmo-app']['app_date'] === '2025-10-30', 'application pin');
    check($pins['Apartment 2, 190 Derby Road, Nottingham, NG7 1NF / selective']['label'] === 'Selective licence', 'selective pin');

    $students = readJson($dir . '/public/students.geojson')['features'];
    check(count($students) === 1 && $students[0]['geometry']['type'] === 'Polygon', 'student outline written');
    check($students[0]['properties']['planning_url'] === 'https://nimby.parknews.co.uk/?app=22-00587-PFUL3', 'links to NIMBY');
    $meta = readJson($dir . '/public/meta.json');
    check($meta['licences'] === 9 && $meta['students'] === 1 && $meta['counts']['hmo'] === 5 && !isset($meta['counts']['selective-app']), 'meta counts (' . json_encode($meta['counts']) . ')');
    check(is_file($dir . '/public/watch-area.geojson') && is_file($dir . '/public/park-boundary.geojson'), 'outlines written');
});

test('A week later: changes are recorded, and described in plain terms', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir] = harness();
    dayOne($http);
    $job->run(false, '2026-11-02 06:00:00');
    dayEight($http);
    $s = $job->run(false, '2026-11-09 06:00:00');
    check($s['inserted'] === 5 && $s['removed'] === 4 && $s['updated'] === 2, 'new, removed, changed (' . json_encode(array_slice($s, 0, 6)) . ')');
    check((int) $pdo->query("SELECT COUNT(*) FROM events WHERE field = 'expiry_date'")->fetchColumn() === 1, 'expiry change logged');
    check((int) $pdo->query("SELECT COUNT(*) FROM events WHERE entity = 'student' AND field = 'bedspaces'")->fetchColumn() === 1, 'student change logged');
    check($pdo->query("SELECT removed_at FROM licences WHERE ref = 'FS-Case-427207101'")->fetchColumn() === '2026-11-09 06:00:00', 'gone licence marked removed, not deleted');

    $items = (new ChangeReport($pdo, readJson($dir . '/public/park-boundary.geojson')))->between('2026-11-02 06:00:00', '2026-11-09 06:00:01');
    $by = [];
    foreach ($items as $i) {
        $by[$i['what'] . ' | ' . $i['address']] = $i;
    }
    $keys = array_keys($by);
    check(isset($by['Licence renewed | 230 Derby Road, Nottingham, NG7 1NX']), 'renewal recognised (' . json_encode($keys) . ')');
    check(isset($by['Newly licensed | 16 Barrack Lane, Nottingham, NG7 1AN']), 'grant recognised');
    check(!isset($by['Application closed | 16 Barrack Lane, Nottingham, NG7 1AN']) && !isset($by['Licence ended | 230 Derby Road, Nottingham, NG7 1NX']),
        'replaced records are not reported as endings');
    check(isset($by['Licence ended | 9 Harrington Drive, Nottingham, NG7 1JQ'])
        && str_contains($by['Licence ended | 9 Harrington Drive, Nottingham, NG7 1JQ']['detail'], 'expired 5 Nov 2026')
        && $by['Licence ended | 9 Harrington Drive, Nottingham, NG7 1JQ']['inside'] === false, 'expiry reported, in the margin');
    check(isset($by['Application closed | 78 Harlaxton Drive, Nottingham, NG7 1JB']), 'withdrawn application reported');
    check(isset($by['New application | 3 Example Street, Nottingham, NG7 1AA']), 'new application');
    check(isset($by['Renewal application | 12 Lenton Road, Nottingham, NG7 1DU']) && $by['Renewal application | 12 Lenton Road, Nottingham, NG7 1DU']['section'] === 'selective', 'renewal application, selective section');
    check(isset($by['Licence dates changed | Apartment 2, 190 Derby Road, Nottingham, NG7 1NF'])
        && str_contains($by['Licence dates changed | Apartment 2, 190 Derby Road, Nottingham, NG7 1NF']['detail'], '10 Jun 2031 → 11 Jun 2031'), 'date change described');
    check(isset($by['New student accommodation scheme | Site of former Ropewalk pub, 107-111 Derby Road']), 'new student scheme');
    check(isset($by['Details changed | 8 Clinton Terrace, Derby Road, Nottingham'])
        && $by['Details changed | 8 Clinton Terrace, Derby Road, Nottingham']['detail'] === 'Bed spaces 26 → 30; cluster rooms 26 → 30', 'student change described (' . json_encode(array_values(array_filter($keys, fn($k) => str_contains($k, 'Clinton')))) . ' ' . json_encode($by['Details changed | 8 Clinton Terrace, Derby Road, Nottingham']['detail'] ?? null) . ')');
    check(count($items) === 9, '9 items in all (' . count($items) . ')');
    check($items[0]['section'] === 'hmo' && end($items)['section'] === 'student', 'HMOs first, student schemes last');

    $pins = pins($dir);
    check(($pins['3 Example Street, Nottingham, NG7 1AA / hmo-app']['first_seen'] ?? null) === '2026-11-09', 'new pins carry their first-seen date');
    check(!isset($pins['9 Harrington Drive, Nottingham, NG7 1JQ / hmo']), 'ended licence off the map');

    $again = $job->run(false, '2026-11-10 06:00:00');
    check($again['inserted'] === 0 && $again['updated'] === 0 && $again['removed'] === 0, 'an unchanged run changes nothing');
});

test('Paging through more than one page of results', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo] = harness();
    dayOne($http);
    $many = [];
    for ($i = 0; $i < 2300; $i++) {
        $many[] = lic(100000 + $i, 'FS-Case-' . (500000000 + $i), 'Selective', "Flat {$i}, 1 Example Court, Nottingham, NG7 1AB", '2024-01-01', '2029-01-01', -1.16, 52.95);
    }
    $http->layers[16] = $many;
    $job->run(false, '2026-11-02 06:00:00');
    check((int) $pdo->query("SELECT COUNT(*) FROM licences WHERE kind = 'selective'")->fetchColumn() === 2300, 'all 2,300 fetched over two pages');
});

test('Safeguards: missing fields, empty layers, outages and server errors keep the old data', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir] = harness();
    dayOne($http);
    $job->run(false, '2026-11-02 06:00:00');
    $before = file_get_contents($dir . '/public/licences.geojson');

    $http->fields[23] = array_diff($http->fields[23], ['EXPIRY_DATE']);
    $failed = false;
    try {
        $job->run(false, '2026-11-03 06:00:00');
    } catch (RuntimeException $e) {
        $failed = str_contains($e->getMessage(), 'missing fields: EXPIRY_DATE');
    }
    check($failed, 'renamed field stops the sync');
    $http->fields[23][] = 'EXPIRY_DATE';

    // One layer suddenly empty.
    $many = [];
    for ($i = 0; $i < 8; $i++) {
        $many[] = lic(200 + $i, "FS-Case-77{$i}", 'Selective', "{$i} Test Road, NG7 1AA", '2024-01-01', '2029-01-01', -1.16, 52.95);
    }
    $http->layers[16] = $many;
    $job->run(false, '2026-11-04 06:00:00');
    $before = file_get_contents($dir . '/public/licences.geojson');
    $removedBefore = (int) $pdo->query('SELECT COUNT(*) FROM licences WHERE removed_at IS NOT NULL')->fetchColumn();
    $http->layers[16] = [];
    $failed = false;
    try {
        $job->run(false, '2026-11-05 06:00:00');
    } catch (RuntimeException $e) {
        $failed = str_contains($e->getMessage(), 'layer 16 returned nothing');
    }
    check($failed, 'an empty layer is treated as an outage');
    check(file_get_contents($dir . '/public/licences.geojson') === $before, 'map files untouched');
    check((int) $pdo->query('SELECT COUNT(*) FROM licences WHERE removed_at IS NOT NULL')->fetchColumn() === $removedBefore, 'nothing more marked removed');

    $http->layers[16] = $many;
    $http->errors[6] = ['code' => 500, 'message' => 'Error performing query operation'];
    $failed = false;
    try {
        $job->run(false, '2026-11-06 06:00:00');
    } catch (RuntimeException $e) {
        $failed = str_contains($e->getMessage(), 'Error performing query operation (code 500)');
    }
    check($failed, 'server error reported');
    $runs = $pdo->query('SELECT status FROM sync_runs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    check($runs === ['ok', 'failed', 'ok', 'failed', 'failed'], 'every run logged (' . implode(',', $runs) . ')');
});

test('Geometry service down: the sync carries on', function () {
    ['job' => $job, 'http' => $http, 'dir' => $dir] = harness();
    dayOne($http);
    $http->bufferError = ['code' => 500, 'message' => 'down'];
    $s = $job->run(false, '2026-11-02 06:00:00');
    check($s['status'] === 'ok' && !is_file($dir . '/public/watch-area.geojson'), 'sync ok without the outline');
    check((bool) array_filter($s['log'], fn($l) => str_contains($l, 'Could not draw the 50 m outline')), 'logged');
});

// ---------------------------------------------------------------- registers

test('Spreadsheet readers', function () {
    $rows = XlsReader::rows((string) file_get_contents(__DIR__ . '/fixtures/hmo-register.xls'));
    check(count($rows) === 1507, 'all .xls rows read, across the string table’s continuation records (' . count($rows) . ')');
    check($rows[2][7] === 'maximum-occupants' && trim((string) $rows[6][0]) === 'Renewal app rec - mandatory' && $rows[6][2] === '11 - 13 Victoria Street', 'text cells');
    check($rows[3][7] === 5.0 && $rows[3][5] === 45198.0, 'number cells');
    check(str_contains((string) $rows[1506][2], 'Long Example Street Name Number 1499'), 'last row intact');

    $x = XlsxReader::rows((string) file_get_contents(__DIR__ . '/fixtures/selective-register.xlsx'));
    check($x[1][0] === 'Our Reference' && $x[2][0] === 'FS-Case-844980230' && $x[2][6] === 'Non HMO Flat', '.xlsx cells');
    check(count($x) === 34 && ($x[3][7] ?? null) === null, '.xlsx rows and empty cells');

    $bad = false;
    try {
        XlsReader::rows('<html>not a spreadsheet</html>');
    } catch (RuntimeException $e) {
        $bad = true;
    }
    check($bad, 'a web page instead of a file is refused');
});

test('Register details are added to the map', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir] = harness(50, true);
    dayOne($http);
    $s = $job->run(false, '2026-11-02 06:00:00');
    $log = implode("\n", $s['log']);
    check(str_contains($log, 'Downloaded the HMO register hmo-public-register-02-11-26.xls (1504 rows)'), 'HMO register downloaded (' . $log . ')');
    check(str_contains($log, 'HMO register: details added to 3 of 5 licensed HMOs'), 'matched by address');
    check(str_contains($log, 'Selective register: property type added to 2 of 2 selective licences'), 'selective matched by reference');

    $pins = pins($dir);
    $p = $pins['230 Derby Road, Nottingham, NG7 1NX / hmo'];
    check($p['occupants'] === 5 && $p['households'] === 5 && $p['renewal'] === true, 'occupancy limits and renewal flag on the pin');
    check(($pins['Flat 2, 1 Balfour Road, Nottingham, NG7 1NY / hmo']['occupants'] ?? null) === 3
        && $pins['Flat 2, 1 Balfour Road, Nottingham, NG7 1NY / hmo']['renewal'] === false, 'flat matched from separate address lines');
    check($pins['12 Lenton Road, Nottingham, NG7 1DU / selective']['property_type'] === 'Non HMO House', 'property type on selective pin');

    // Same link next time: no download, cached rows reused.
    $http->requests = [];
    $job->run(false, '2026-11-03 06:00:00');
    $raws = array_map(fn($r) => $r[1], array_filter($http->requests, fn($r) => $r[0] === 'RAW'));
    check(array_values($raws) === ['https://council.test/hmo', 'https://council.test/selective'], 'only the pages are fetched when the files are unchanged');

    // Council page broken: the sync still succeeds and keeps the details.
    $http->raw['https://council.test/hmo'] = '<p>Page moved</p>';
    $s = $job->run(false, '2026-11-04 06:00:00');
    check($s['status'] === 'ok' && in_array('HMO register skipped: no register link found on the council page', $s['log'], true), 'register problems never stop the sync');
    check((int) $pdo->query('SELECT max_occupants FROM licences WHERE ref = \'FS-Case-486116703\'')->fetchColumn() === 5, 'details kept');
});

// ---------------------------------------------------------------- email

function emailKit(PDO $pdo, bool $open = true): array
{
    $settings = new Settings($pdo);
    if ($open) {
        $settings->set('signup_open', '1');
    }
    $store = new SubscriberStore($pdo);
    $mailer = new LogMailer();
    $signup = new SignupService($store, $settings, $mailer, 'https://landlords.npra.co.uk');
    return compact('settings', 'store', 'mailer', 'signup');
}

function tokenFrom(string $text): string
{
    preg_match('/confirm\.php\?token=([a-f0-9]+)/', $text, $m);
    return $m[1] ?? '';
}

function addActive(SignupService $signup, LogMailer $mailer, string $email, string $now, string $ip): void
{
    $signup->subscribe($email, null, true, '', $ip, $now);
    $signup->confirm(tokenFrom(end($mailer->sent)->text), $now);
}

test('Sign-up: closed, validation, honeypot', function () {
    ['pdo' => $pdo] = harness();
    ['signup' => $signup, 'mailer' => $mailer, 'settings' => $settings] = emailKit($pdo, false);
    check($signup->subscribe('a@example.com', null, true, '', '1.2.3.4', '2026-11-01 10:00:00')['code'] === 'closed', 'refused while sign-up is closed');
    $settings->set('signup_open', '1');
    check($signup->subscribe('not-an-email', null, true, '', '1.2.3.4', '2026-11-01 10:00:00')['code'] === 'invalid_email', 'bad address rejected');
    check($signup->subscribe('a@example.com', null, false, '', '1.2.3.4', '2026-11-01 10:00:00')['code'] === 'consent', 'consent required');
    $bot = $signup->subscribe('bot@example.com', null, true, 'http://spam', '1.2.3.4', '2026-11-01 10:00:00');
    check($bot['ok'] && $bot['message'] === SignupService::CHECK_INBOX, 'honeypot gets the normal reply');
    check(count($mailer->sent) === 0 && (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn() === 0, 'but nothing is stored or sent');
});

test('Sign-up: double opt-in, expiry and rate limit', function () {
    ['pdo' => $pdo] = harness();
    ['signup' => $signup, 'mailer' => $mailer, 'store' => $store] = emailKit($pdo);
    $r = $signup->subscribe('  Jane.Doe@Example.COM ', "Jane\x07 Doe", true, '', '1.2.3.4', '2026-11-01 10:00:00');
    check($r['ok'], 'check-your-inbox reply');
    $row = $store->findByEmail('jane.doe@example.com');
    check($row && $row['status'] === 'pending' && $row['name'] === 'Jane Doe', 'stored as pending, lower-cased, name cleaned');
    $msg = $mailer->sent[0];
    check($msg->subject === 'Confirm your LANDLORDS weekly email' && str_contains($msg->html, 'Hello Jane Doe') && str_contains($msg->text, 'https://landlords.npra.co.uk/confirm.php?token='), 'confirmation email');
    $token = tokenFrom($msg->text);
    check(strlen($token) === 48 && $row['confirm_hash'] === hash('sha256', $token), 'only a hash of the token is stored');
    check($signup->confirm($token, '2026-11-01 11:00:00')['code'] === 'confirmed' && $store->findByEmail('jane.doe@example.com')['status'] === 'active', 'link confirms');
    check($signup->confirm($token, '2026-11-01 11:01:00')['code'] === 'invalid', 'link works once');

    $signup->subscribe('late@example.com', null, true, '', '1.1.1.1', '2026-11-01 10:00:00');
    check($signup->confirm(tokenFrom(end($mailer->sent)->text), '2026-11-09 10:00:01')['code'] === 'expired', 'link expires after 7 days');
    for ($i = 0; $i < SignupService::MAX_PER_IP_PER_HOUR; $i++) {
        $signup->subscribe("x{$i}@example.com", null, true, '', '9.9.9.9', '2026-11-01 10:20:00');
    }
    check($signup->subscribe('y@example.com', null, true, '', '9.9.9.9', '2026-11-01 10:21:00')['code'] === 'too_many', 'rate limited');
});

test('Unsubscribe and deletion', function () {
    ['pdo' => $pdo] = harness();
    ['signup' => $signup, 'mailer' => $mailer, 'store' => $store] = emailKit($pdo);
    addActive($signup, $mailer, 'leaver@example.com', '2026-11-01 10:00:00', '1.1.1.1');
    $id = (int) $store->findByEmail('leaver@example.com')['id'];
    $url = $signup->unsubscribeUrl($id);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    check(str_starts_with($url, 'https://landlords.npra.co.uk/unsubscribe.php?s=' . $id . '&t='), 'unsubscribe link');
    check(!$signup->unsubscribe($id, str_repeat('0', 32), '2026-11-02 10:00:00'), 'wrong token refused');
    check($signup->unsubscribe($id, $q['t'], '2026-11-02 10:00:00') && $store->find($id)['status'] === 'unsubscribed', 'unsubscribed');
    $p = $store->purge('2026-12-02 10:00:01');
    check($p['unsubscribed'] === 1 && $store->find($id) === null, 'unsubscribed address deleted after 30 days');
});

function digestKit(array $h, array $kit, int $maxPerRun = 200): DigestSender
{
    $builder = new DigestBuilder('https://landlords.npra.co.uk', 50, "the Nottingham Park Residents' Association (NPRA)");
    $report = new ChangeReport($h['pdo'], readJson($h['dir'] . '/public/park-boundary.geojson'));
    return new DigestSender($h['pdo'], $kit['store'], $kit['signup'], $kit['mailer'], $builder, $report, $maxPerRun, 0);
}

test('Monday digest', function () {
    $h = harness();
    dayOne($h['http']);
    $h['job']->run(false, '2026-11-02 06:00:00');
    $kit = emailKit($h['pdo']);
    foreach (['a@example.com', 'b@example.com'] as $i => $email) {
        addActive($kit['signup'], $kit['mailer'], $email, '2026-10-20 10:00:00', "10.0.0.{$i}");
    }
    $kit['mailer']->sent = [];
    $sender = digestKit($h, $kit);

    $r = $sender->run('2026-11-02 07:11:00');
    check($r['status'] === 'empty' && !$kit['mailer']->sent, 'first week: history only, nothing sent');

    dayEight($h['http']);
    $h['http']->layers[26][0]['attributes']['ADDRESS'] = '3 <b>Example</b> Street & Co, Nottingham, NG7 1AA';
    $h['job']->run(false, '2026-11-08 06:00:00');
    $r = $sender->run('2026-11-09 07:11:00');
    check($r['status'] === 'sent' && $r['items'] === 9 && $r['sent'] === 2, 'digest sent to both (' . json_encode(array_slice($r, 0, 5)) . ')');
    $m = $kit['mailer']->sent[0];
    check($m->subject === 'LANDLORDS weekly: 9 changes to rented homes in and around The Park', 'subject (' . $m->subject . ')');
    check(str_contains($m->text, 'in the week to Monday 9 November'), 'week named');
    $h1 = strpos($m->html, 'Houses in multiple occupation (HMOs)');
    $h2 = strpos($m->html, 'Other rented homes (selective licences)');
    $h3 = strpos($m->html, 'Purpose-built student accommodation');
    check($h1 !== false && $h1 < $h2 && $h2 < $h3, 'three sections in order');
    check(str_contains($m->html, 'Licence renewed') && str_contains($m->html, '230 Derby Road, NG7 1NX'), 'renewal listed with a tidy address');
    check(preg_match('#https://landlords\.npra\.co\.uk/\?p=[0-9a-f]{10}#', $m->html) === 1, 'links to pins');
    check(str_contains($m->html, '(just outside The Park)'), 'margin properties marked');
    check(!str_contains($m->html, '<b>Example</b>') && str_contains($m->html, '&lt;b&gt;Example&lt;/b&gt; Street &amp; Co'), 'council text escaped');
    check(str_starts_with($m->headers['List-Unsubscribe'], '<https://landlords.npra.co.uk/unsubscribe.php?s=')
        && $m->headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click', 'one-click unsubscribe headers');
    check(str_contains($m->text, 'LICENCE ENDED') === false && str_contains($m->text, 'Licence ended: 9 Harrington Drive, NG7 1JQ (just outside The Park)'), 'plain text version');

    $kit['mailer']->sent = [];
    $r = $sender->run('2026-11-16 07:11:00');
    check($r['status'] === 'empty' && !$kit['mailer']->sent, 'quiet week: nothing sent');
    $periods = $h['pdo']->query('SELECT period_start, period_end FROM digests ORDER BY id')->fetchAll();
    check($periods[2]['period_start'] === $periods[1]['period_end'], 'each digest starts where the last ended');
});

test('Monday digest: interrupted runs finish without repeats; test digest', function () {
    $h = harness();
    dayOne($h['http']);
    $h['job']->run(false, '2026-11-02 06:00:00');
    dayEight($h['http']);
    $h['job']->run(false, '2026-11-08 06:00:00');
    $kit = emailKit($h['pdo']);
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $i => $email) {
        addActive($kit['signup'], $kit['mailer'], $email, '2026-10-20 10:00:00', "10.0.0.{$i}");
    }
    $kit['mailer']->sent = [];
    $r = digestKit($h, $kit, 2)->run('2026-11-09 07:11:00');
    check($r['status'] === 'partial' && $r['sent'] === 2, 'stops at the per-run limit');
    $r = digestKit($h, $kit, 2)->run('2026-11-09 08:11:00');
    check($r['status'] === 'sent' && $r['sent'] === 1, 'next run finishes the same digest');
    $to = array_map(fn($x) => $x->to, $kit['mailer']->sent);
    check(count($to) === 3 && count(array_unique($to)) === 3, 'nobody emailed twice');

    $kit['mailer']->sent = [];
    $r = digestKit($h, $kit)->test('2026-11-20 07:11:00', 'landlords@npra.co.uk');
    check(count($kit['mailer']->sent) === 1 && $kit['mailer']->sent[0]->to === 'landlords@npra.co.uk'
        && str_starts_with($kit['mailer']->sent[0]->subject, '[TEST] '), 'test goes only to the site mailbox, marked');
    check($r['items'] === 9, 'with no changes this week, the test covers the last 90 days (' . $r['items'] . ')');
    check((int) $h['pdo']->query('SELECT COUNT(*) FROM digests')->fetchColumn() === 1, 'nothing recorded by the test');
});

test('Geometry helpers', function () {
    $fc = Geo::esriRingsToGeoJson([[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]]);
    check(Geo::pointInGeoJson(0.5, 0.5, $fc) && !Geo::pointInGeoJson(1.5, 0.5, $fc), 'point in polygon');
    [$x, $y] = Geo::centroid([[[0, 0], [0, 2], [2, 2], [2, 0], [0, 0]]]);
    check(abs($x - 1) < 1e-9 && abs($y - 1) < 1e-9, 'centroid');
});

echo "\n{$passes} checks passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
