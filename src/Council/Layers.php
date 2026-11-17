<?php
declare(strict_types=1);

namespace Landlords\Council;

/**
 * The council's housing layers that LANDLORDS reads, from the
 * Core_services/Housing_and_Property map service.
 */
final class Layers
{
    public const LICENCE_FIELDS = ['REF', 'LICENSING_SCHEME', 'ADDRESS', 'DATE_ISSUED', 'EXPIRY_DATE'];
    public const APPLICATION_FIELDS = ['REF', 'LICENSING_SCHEME', 'ADDRESS', 'APP_DATE'];
    public const STUDENT_FIELDS = [
        'SCHEME_UNIQUE_ID', 'NAME', 'ADDRESS', 'POSTCODE', 'BEDSPACES', 'STUDIOS', 'CLUSTERS',
        'TYPE_OF_SCHEME', 'ACADEMIC_YEAR_COMPLETED_OR_PRO', 'PLANNING_APPLICATION_REFERENCE', 'PROVIDER',
    ];

    /** Purpose Built Student Accommodation (polygons). */
    public const STUDENT_LAYER = 21;

    /**
     * Point layers of licences and pending applications.
     *
     * @return array<int, array{kind: string, scheme: string, stage: string, fields: list<string>}>
     */
    public static function licensing(): array
    {
        return [
            23 => ['kind' => 'hmo', 'scheme' => 'Mandatory', 'stage' => 'licence', 'fields' => self::LICENCE_FIELDS],
            6  => ['kind' => 'hmo', 'scheme' => 'Additional', 'stage' => 'licence', 'fields' => self::LICENCE_FIELDS],
            16 => ['kind' => 'selective', 'scheme' => 'Selective', 'stage' => 'licence', 'fields' => self::LICENCE_FIELDS],
            25 => ['kind' => 'hmo', 'scheme' => 'Mandatory', 'stage' => 'application', 'fields' => self::APPLICATION_FIELDS],
            26 => ['kind' => 'hmo', 'scheme' => 'Additional', 'stage' => 'application', 'fields' => self::APPLICATION_FIELDS],
            27 => ['kind' => 'selective', 'scheme' => 'Selective', 'stage' => 'application', 'fields' => self::APPLICATION_FIELDS],
        ];
    }

    /** "HMO licence (mandatory)", "Selective licence application", ... */
    public static function label(string $kind, string $scheme, string $stage): string
    {
        $what = $kind === 'hmo' ? 'HMO licence' : 'Selective licence';
        if ($stage === 'application') {
            return $what . ' application' . ($kind === 'hmo' ? ' (' . strtolower($scheme) . ')' : '');
        }
        return $what . ($kind === 'hmo' ? ' (' . strtolower($scheme) . ')' : '');
    }
}
