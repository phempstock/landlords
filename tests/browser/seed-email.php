<?php
declare(strict_types=1);

/**
 * Seed the email walk-through site: 20 licences of history (never emailed),
 * then 5 new HMO licences and one new application in The Park, plus the map
 * files. Run from the site copy's root.
 */

use Landlords\Council\Records;
use Landlords\Export\GeoJsonExporter;
use Landlords\Storage\Database;
use Landlords\Storage\TrackedStore;
use Landlords\Support\Address;

require getcwd() . '/bootstrap.php';

$config = landlords_config();
$pdo = Database::connect($config['db']);
$store = new TrackedStore($pdo, 'licences', 'lkey', 'licence', Records::LICENCE_TRACKED);
$students = new TrackedStore($pdo, 'student_schemes', 'scheme_id', 'student', Records::STUDENT_TRACKED, Records::STUDENT_QUIET);

$record = static function (int $i, string $stage, string $date) {
    $address = (10 + $i) . ' Park Valley, Nottingham, NG7 1BS';
    return [
        'lkey' => "hmo-mandatory-{$stage}:FS-Case-{$i}:" . substr(sha1($address), 0, 8), 'ref' => "FS-Case-{$i}",
        'kind' => 'hmo', 'scheme' => 'Mandatory', 'stage' => $stage, 'address' => $address,
        'addr_key' => Address::key($address), 'postcode' => 'NG7 1BS',
        'app_date' => $stage === 'application' ? $date : null,
        'date_issued' => $stage === 'licence' ? $date : null,
        'expiry_date' => $stage === 'licence' ? (string) ((int) substr($date, 0, 4) + 5) . substr($date, 4) : null,
        'lat' => round(52.9500 + $i * 0.0001, 6), 'lng' => round(-1.1650 + $i * 0.0002, 6),
    ];
};

$now = time();
$history = [];
for ($i = 1; $i <= 20; $i++) {
    $history[] = $record($i, 'licence', gmdate('Y-m-d', $now - (400 + $i) * 86400));
}
$store->sync($history, gmdate('Y-m-d H:i:s', $now - 30 * 86400), true);            // first run: history
$students->sync([], gmdate('Y-m-d H:i:s', $now - 30 * 86400), true);

$fresh = $history;
for ($i = 21; $i <= 25; $i++) {
    $fresh[] = $record($i, 'licence', gmdate('Y-m-d', $now - ($i - 20) * 86400));
}
$fresh[] = $record(26, 'application', gmdate('Y-m-d', $now - 3 * 86400));
$store->sync($fresh, gmdate('Y-m-d H:i:s', $now - 86400));                          // yesterday: six new

$park = ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['NAME' => 'The Park'],
    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-1.1693, 52.9476], [-1.1559, 52.9476], [-1.1559, 52.9555], [-1.1693, 52.9555], [-1.1693, 52.9476]]]]]]];
$exporter = new GeoJsonExporter($config['paths']['public_data']);
$exporter->writeBoundary($park);
$exporter->write($store->allActive('address'), [], gmdate('Y-m-d H:i:s'), $park, 0);
echo "Seeded 20 historical licences, 5 new licences and 1 new application\n";
