<?php
declare(strict_types=1);

namespace Landlords\Council;

use Landlords\Support\Address;
use Landlords\Support\Geo;

/**
 * Turns raw ArcGIS features into the tidy arrays LANDLORDS stores.
 */
final class Records
{
    /** Licence fields compared between runs; a change to any of them is logged as an event. */
    public const LICENCE_TRACKED = [
        'ref', 'kind', 'scheme', 'stage', 'address', 'addr_key', 'postcode',
        'app_date', 'date_issued', 'expiry_date', 'lat', 'lng',
    ];

    public const STUDENT_TRACKED = [
        'name', 'address', 'postcode', 'bedspaces', 'studios', 'clusters', 'scheme_type',
        'academic_year', 'planning_ref', 'provider', 'lat', 'lng',
    ];

    /** Stored but not compared (the outline can be redrawn without it being news). */
    public const STUDENT_QUIET = ['geometry'];

    /**
     * @param array{kind: string, scheme: string, stage: string} $layer from Layers::licensing()
     * @return array<string, mixed>|null null when the feature has no reference or address
     */
    public static function licence(array $feature, array $layer): ?array
    {
        $a = $feature['attributes'] ?? [];
        $ref = Address::text($a['REF'] ?? null);
        $address = Address::text($a['ADDRESS'] ?? null);
        if ($ref === null || $address === null) {
            return null;
        }
        $g = $feature['geometry'] ?? null;
        $addrKey = Address::key($address);

        return [
            // A reference can appear twice in one layer (old numeric references were
            // reused), so the key also carries the layer and a hash of the address.
            'lkey'        => sprintf('%s-%s-%s:%s:%s', $layer['kind'], strtolower($layer['scheme']), $layer['stage'],
                                     $ref, substr(sha1($addrKey), 0, 8)),
            'ref'         => $ref,
            'kind'        => $layer['kind'],
            'scheme'      => $layer['scheme'],
            'stage'       => $layer['stage'],
            'address'     => $address,
            'addr_key'    => $addrKey,
            'postcode'    => Address::postcode($address),
            'app_date'    => self::date($a['APP_DATE'] ?? null),
            'date_issued' => self::date($a['DATE_ISSUED'] ?? null),
            'expiry_date' => self::date($a['EXPIRY_DATE'] ?? null),
            'lat'         => is_array($g) && is_numeric($g['y'] ?? null) ? round((float) $g['y'], 6) : null,
            'lng'         => is_array($g) && is_numeric($g['x'] ?? null) ? round((float) $g['x'], 6) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function student(array $feature): ?array
    {
        $a = $feature['attributes'] ?? [];
        $id = Address::text($a['SCHEME_UNIQUE_ID'] ?? null) ?? (isset($a['OBJECTID']) ? 'OID' . $a['OBJECTID'] : null);
        if ($id === null) {
            return null;
        }
        $rings = $feature['geometry']['rings'] ?? null;
        $geometry = null;
        $lat = $lng = null;
        if (is_array($rings) && $rings) {
            $fc = Geo::esriRingsToGeoJson(self::roundRings($rings));
            $geometry = json_encode($fc['features'][0]['geometry'], JSON_UNESCAPED_SLASHES);
            [$lng, $lat] = Geo::centroid($rings);
            $lat = round($lat, 6);
            $lng = round($lng, 6);
        }
        $int = static fn($v) => is_numeric($v) ? (int) round((float) $v) : null;

        return [
            'scheme_id'     => $id,
            'name'          => Address::text($a['NAME'] ?? null),
            'address'       => Address::text($a['ADDRESS'] ?? null),
            'postcode'      => Address::text($a['POSTCODE'] ?? null) ?? Address::postcode((string) ($a['ADDRESS'] ?? '')),
            'bedspaces'     => $int($a['BEDSPACES'] ?? null),
            'studios'       => $int($a['STUDIOS'] ?? null),
            'clusters'      => $int($a['CLUSTERS'] ?? null),
            'scheme_type'   => Address::text($a['TYPE_OF_SCHEME'] ?? null),
            'academic_year' => Address::text($a['ACADEMIC_YEAR_COMPLETED_OR_PRO'] ?? null),
            'planning_ref'  => Address::text($a['PLANNING_APPLICATION_REFERENCE'] ?? null),
            'provider'      => Address::text($a['PROVIDER'] ?? null),
            'lat'           => $lat,
            'lng'           => $lng,
            'geometry'      => $geometry,
        ];
    }

    /** ArcGIS dates are milliseconds since 1970 (UTC midnight for these fields). */
    public static function date(mixed $ms): ?string
    {
        if (!is_numeric($ms)) {
            return null;
        }
        return gmdate('Y-m-d', (int) floor(((float) $ms) / 1000));
    }

    private static function roundRings(array $rings): array
    {
        return array_map(static fn(array $ring) => array_map(
            static fn(array $p) => [round((float) $p[0], 6), round((float) $p[1], 6)], $ring), $rings);
    }
}
