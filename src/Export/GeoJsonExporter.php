<?php
declare(strict_types=1);

namespace Landlords\Export;

use Landlords\Council\Layers;
use Landlords\Support\Geo;
use RuntimeException;

/**
 * Writes the static files the map page loads, so visitors never touch
 * the database or the council's server.
 *
 *  - licences.geojson : one point per licence or pending application
 *  - students.geojson : one outline per student accommodation scheme
 *  - meta.json        : when the data was last refreshed, and counts
 */
final class GeoJsonExporter
{
    /** The council's own pages about each scheme. */
    public const SCHEME_PAGES = [
        'Mandatory'  => 'https://www.nottinghamcity.gov.uk/hmo',
        'Additional' => 'https://www.nottinghamcity.gov.uk/additionalhmo',
        'Selective'  => 'https://www.nottinghamcity.gov.uk/information-for-residents/housing/private-rented-accommodation/information-for-landlords/licensing-for-landlords/selective-licensing',
    ];

    public function __construct(private string $publicDataDir, private string $nimbyUrl = 'https://nimby.parknews.co.uk')
    {
        $this->nimbyUrl = rtrim($nimbyUrl, '/');
    }

    /**
     * @param list<array<string, mixed>> $licences rows from the licences table
     * @param list<array<string, mixed>> $students rows from the student_schemes table
     * @param array|null $coreArea the conservation area (GeoJSON); anything outside it is
     *        flagged 'inside' => false (it is in the surrounding margin)
     */
    public function write(array $licences, array $students, string $syncedAtUtc, ?array $coreArea = null, float $bufferMetres = 0): array
    {
        $features = [];
        $outside = 0;
        $counts = [];
        foreach ($licences as $r) {
            if ($r['lat'] === null || $r['lng'] === null) {
                continue;
            }
            $inside = $coreArea === null || Geo::pointInGeoJson((float) $r['lng'], (float) $r['lat'], $coreArea);
            $outside += $inside ? 0 : 1;
            $layer = self::layerId($r['kind'], $r['stage']);
            $counts[$layer] = ($counts[$layer] ?? 0) + 1;
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round((float) $r['lng'], 6), round((float) $r['lat'], 6)]],
                'properties' => array_filter([
                    'id'         => self::id((string) $r['lkey']),
                    'layer'      => $layer,
                    'ref'        => $r['ref'],
                    'scheme'     => $r['scheme'],
                    'label'      => Layers::label($r['kind'], $r['scheme'], $r['stage']),
                    'address'    => $r['address'],
                    'app_date'   => $r['app_date'],
                    'issued'     => $r['date_issued'],
                    'expires'    => $r['expiry_date'],
                    'occupants'  => $r['max_occupants'] !== null ? (int) $r['max_occupants'] : null,
                    'households' => $r['max_households'] !== null ? (int) $r['max_households'] : null,
                    'renewal'    => $r['register_type'] !== null ? stripos((string) $r['register_type'], 'renewal') !== false : null,
                    'property_type' => $r['property_type'],
                    'inside'     => $inside,
                    // Null for records loaded on the first run, so the map never badges them as new.
                    'first_seen' => (int) $r['backfilled'] === 1 ? null : substr((string) $r['first_seen_at'], 0, 10),
                    'url'        => self::SCHEME_PAGES[$r['scheme']] ?? null,
                ], static fn($v) => $v !== null),
            ];
        }

        $schemes = [];
        foreach ($students as $s) {
            $geometry = $s['geometry'] ? json_decode((string) $s['geometry'], true) : null;
            if (!is_array($geometry)) {
                continue;
            }
            $inside = $coreArea === null || $s['lat'] === null
                || Geo::pointInGeoJson((float) $s['lng'], (float) $s['lat'], $coreArea);
            $schemes[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => array_filter([
                    'id'           => 'pbsa-' . self::id((string) $s['scheme_id']),
                    'layer'        => 'student',
                    'name'         => $s['name'],
                    'address'      => $s['address'],
                    'postcode'     => $s['postcode'],
                    'bedspaces'    => $s['bedspaces'] !== null ? (int) $s['bedspaces'] : null,
                    'studios'      => $s['studios'] !== null ? (int) $s['studios'] : null,
                    'clusters'     => $s['clusters'] !== null ? (int) $s['clusters'] : null,
                    'scheme_type'  => $s['scheme_type'],
                    'year'         => $s['academic_year'],
                    'planning_ref' => $s['planning_ref'],
                    'planning_url' => $s['planning_ref'] ? $this->nimbyUrl . '/?app=' . self::slug((string) $s['planning_ref']) : null,
                    'provider'     => $s['provider'],
                    'centre'       => $s['lat'] !== null ? [round((float) $s['lng'], 6), round((float) $s['lat'], 6)] : null,
                    'inside'       => $inside,
                    'first_seen'   => (int) $s['backfilled'] === 1 ? null : substr((string) $s['first_seen_at'], 0, 10),
                ], static fn($v) => $v !== null),
            ];
        }

        $meta = [
            'synced_at'     => $syncedAtUtc . 'Z',
            'licences'      => count($features),
            'students'      => count($schemes),
            'unplaced'      => count($licences) - count($features),
            'outside'       => $outside,
            'counts'        => $counts,
            'buffer_metres' => $bufferMetres,
        ];

        $this->writeAtomically('licences.geojson', ['type' => 'FeatureCollection', 'features' => $features]);
        $this->writeAtomically('students.geojson', ['type' => 'FeatureCollection', 'features' => $schemes]);
        $this->writeAtomically('meta.json', $meta);
        return $meta;
    }

    /** hmo / hmo-app / selective / selective-app: the map's layers. */
    public static function layerId(string $kind, string $stage): string
    {
        return $kind . ($stage === 'application' ? '-app' : '');
    }

    /** A short, stable id for links such as /?p=3f2a9c01d4 */
    public static function id(string $key): string
    {
        return substr(sha1($key), 0, 10);
    }

    /** "22/00587/PFUL3" -> "22-00587-PFUL3", NIMBY's link format */
    public static function slug(string $ref): string
    {
        return strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ref), '-'));
    }

    /** Write the boundary outline for the map. */
    public function writeBoundary(array $geojson): void
    {
        $this->writeAtomically('park-boundary.geojson', $geojson);
    }

    /** Write the widened outline, or remove it when there is no margin. */
    public function writeWatchArea(?array $geojson): void
    {
        if ($geojson === null) {
            @unlink($this->publicDataDir . '/watch-area.geojson');
            return;
        }
        $this->writeAtomically('watch-area.geojson', $geojson);
    }

    private function writeAtomically(string $name, array $data): void
    {
        if (!is_dir($this->publicDataDir) && !mkdir($this->publicDataDir, 0755, true) && !is_dir($this->publicDataDir)) {
            throw new RuntimeException("Cannot create {$this->publicDataDir}");
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $target = $this->publicDataDir . '/' . $name;
        $tmp = $target . '.tmp';
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $target)) {
            throw new RuntimeException("Could not write {$target}");
        }
    }
}
