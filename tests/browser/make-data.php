<?php
declare(strict_types=1);

/**
 * Writes realistic map data for the browser tests by running the real sync
 * against a fake council server: about the numbers found in The Park in
 * November 2026 (HMOs, selective licences, applications, blocks of flats,
 * student schemes), on two days a week apart so some pins are new.
 *
 *   php tests/browser/make-data.php public/data
 *
 * Also writes expected.json next to the data, for map.test.js.
 */

use Landlords\Council\ArcGisClient;
use Landlords\Council\Records;
use Landlords\Export\GeoJsonExporter;
use Landlords\Storage\Database;
use Landlords\Storage\TrackedStore;
use Landlords\SyncJob;
use Landlords\Tests\FakeHttpClient;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/FakeHttpClient.php';

$out = $argv[1] ?? 'public/data';
mt_srand(20261117);

$streets = ['Derby Road', 'Lenton Road', 'Tunnel Road', 'Clumber Road East', 'Lincoln Circus', 'Harlaxton Drive',
            'Park Valley', 'Newcastle Drive', 'Barrack Lane', 'Castle Boulevard', 'Huntingdon Drive', 'Cavendish Road East'];
$point = static fn() => [-1.168 + mt_rand(0, 1500) / 100000, 52.9475 + mt_rand(0, 750) / 100000];
$ms = static fn(string $d) => strtotime($d . ' UTC') * 1000;
$ref = 500000000;
$feature = static function (string $address, array $xy, array $attrs) use (&$ref) {
    return ['attributes' => ['OBJECTID' => $ref, 'REF' => 'FS-Case-' . ($ref++), 'ADDRESS' => $address] + $attrs,
            'geometry' => ['x' => $xy[0], 'y' => $xy[1]]];
};
$licence = static function (string $address, array $xy, string $scheme) use ($feature, $ms) {
    $issued = sprintf('20%02d-%02d-%02d', mt_rand(22, 26), mt_rand(1, 12), mt_rand(1, 28));
    return $feature($address, $xy, ['LICENSING_SCHEME' => $scheme, 'DATE_ISSUED' => $ms($issued),
        'EXPIRY_DATE' => $ms((string) ((int) substr($issued, 0, 4) + 5) . substr($issued, 4))]);
};
$application = static fn(string $address, array $xy, string $scheme) => $feature($address, $xy,
    ['LICENSING_SCHEME' => $scheme, 'APP_DATE' => $ms(sprintf('2026-%02d-%02d', mt_rand(1, 10), mt_rand(1, 28)))]);
$addr = static fn(int $n, string $street) => "{$n} {$street}, Nottingham, NG7 1" . chr(65 + $n % 26) . chr(65 + strlen($street) % 26);

$http = new FakeHttpClient();
foreach ([23 => ['Mandatory', 60], 6 => ['Additional', 25]] as $layer => [$scheme, $n]) {
    for ($i = 0; $i < $n; $i++) {
        $http->layers[$layer][] = $licence($addr(mt_rand(1, 240), $streets[array_rand($streets)]), $point(), $scheme);
    }
}
// A block of flats with several additional HMO licences at one point.
$block = $point();
for ($f = 1; $f <= 6; $f++) {
    $http->layers[6][] = $licence("{$f} Westcliffe Court, 45-47 Lenton Road, Nottingham, NG7 1DW", $block, 'Additional');
}
// Selective licences: houses, and apartment blocks.
for ($i = 0; $i < 450; $i++) {
    $http->layers[16][] = $licence($addr(mt_rand(1, 300), $streets[array_rand($streets)]), $point(), 'Selective');
}
for ($b = 0; $b < 8; $b++) {
    $xy = $point();
    $street = $streets[array_rand($streets)];
    $num = mt_rand(1, 200);
    for ($f = 1; $f <= mt_rand(4, 30); $f++) {
        $http->layers[16][] = $licence("Apartment {$f}, {$num} {$street}, Nottingham, NG7 1NF", $xy, 'Selective');
    }
}
for ($i = 0; $i < 30; $i++) {
    $http->layers[25][] = $application($addr(mt_rand(1, 240), $streets[array_rand($streets)]), $point(), 'Mandatory');
    $http->layers[27][] = $application($addr(mt_rand(1, 240), $streets[array_rand($streets)]), $point(), 'Selective');
}
for ($i = 0; $i < 18; $i++) {
    $http->layers[26][] = $application($addr(mt_rand(1, 240), $streets[array_rand($streets)]), $point(), 'Additional');
}
$pbsa = static function (string $id, string $name, string $address, int $beds, string $year, ?string $ref, string $provider, array $xy) {
    $d = 0.00025;
    [$x, $y] = $xy;
    return ['attributes' => ['OBJECTID' => crc32($id) % 99999, 'SCHEME_UNIQUE_ID' => $id, 'NAME' => $name, 'ADDRESS' => $address,
        'POSTCODE' => null, 'BEDSPACES' => $beds, 'STUDIOS' => (int) ($beds / 5), 'CLUSTERS' => $beds - (int) ($beds / 5),
        'TYPE_OF_SCHEME' => 'Clusters/studios', 'ACADEMIC_YEAR_COMPLETED_OR_PRO' => $year,
        'PLANNING_APPLICATION_REFERENCE' => $ref, 'PROVIDER' => $provider],
        'geometry' => ['rings' => [[[$x - $d, $y - $d], [$x - $d, $y + $d], [$x + $d * 2, $y + $d], [$x + $d * 2, $y - $d], [$x - $d, $y - $d]]]]];
};
$http->layers[21] = [
    $pbsa('S1', '8 Clinton Terrace', '8 Clinton Terrace, Derby Road, Nottingham', 26, 'Archive', '22/00587/PFUL3', 'Clinton View Ltd', $point()),
    $pbsa('S2', 'Boulevard Wharf', 'Land North Of Canal Castle Boulevard, Nottingham, NG7 1FN', 66, '2020/21', '17/00882/PFUL3', 'fhp Student Living', $point()),
    $pbsa('S3', 'Site of former Ropewalk pub', '107-111 Derby Road', 59, '2027/28', '24/01952/PFUL3', 'Unknown', $point()),
];

$pdo = Database::connect(['dsn' => 'sqlite::memory:']);
foreach (glob(dirname(__DIR__, 2) . '/migrations/sqlite/*.sql') as $file) {
    Database::migrate($pdo, $file);
}
$council = new ArcGisClient($http, 'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Housing_and_Property/MapServer',
    'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Local_Plan_2020/MapServer/49',
    'https://maps164.nottinghamcity.gov.uk/server/rest/services/Utilities/Geometry/GeometryServer');
$licences = new TrackedStore($pdo, 'licences', 'lkey', 'licence', Records::LICENCE_TRACKED);
$students = new TrackedStore($pdo, 'student_schemes', 'scheme_id', 'student', Records::STUDENT_TRACKED, Records::STUDENT_QUIET);
$cache = sys_get_temp_dir() . '/landlords-browser-' . getmypid();
$job = new SyncJob($council, $licences, $students, new GeoJsonExporter($out), $pdo, 'The Park', $cache, 0.8, 50);

$week = gmdate('Y-m-d H:i:s', time() - 8 * 86400);
$job->run(false, $week);

// A day ago: two new HMO applications and a new licence, so the map has "new" pins.
$http->layers[26][] = $application('3 Example Street, Nottingham, NG7 1AA', [-1.1610, 52.9500], 'Additional');
$http->layers[26][] = $application('5 Example Street, Nottingham, NG7 1AA', [-1.1612, 52.9502], 'Additional');
$http->layers[23][] = $licence('16 Barrack Lane, Nottingham, NG7 1AN', [-1.1640, 52.9532], 'Mandatory');
$job->run(false, gmdate('Y-m-d H:i:s', time() - 86400));

$geo = json_decode((string) file_get_contents($out . '/licences.geojson'), true);
$byLayer = [];
foreach ($geo['features'] as $f) {
    $byLayer[$f['properties']['layer']] = ($byLayer[$f['properties']['layer']] ?? 0) + 1;
}
$block = array_values(array_filter($geo['features'], fn($f) => str_contains($f['properties']['address'], 'Westcliffe Court')));
$new = array_values(array_filter($geo['features'], fn($f) => ($f['properties']['address'] ?? '') === '16 Barrack Lane, Nottingham, NG7 1AN'));
file_put_contents($out . '/expected.json', json_encode([
    'byLayer' => $byLayer + ['student' => 3],
    'blockIds' => array_map(fn($f) => $f['properties']['id'], $block),
    'newId' => $new[0]['properties']['id'],
    'studentId' => json_decode((string) file_get_contents($out . '/students.geojson'), true)['features'][0]['properties']['id'],
], JSON_PRETTY_PRINT));
echo 'Wrote ', count($geo['features']), " pins and 3 student schemes to {$out}\n";
