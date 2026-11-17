<?php
declare(strict_types=1);

namespace Landlords\Digest;

use Landlords\Council\Layers;
use Landlords\Export\GeoJsonExporter;
use Landlords\Support\Geo;
use PDO;

/**
 * Works out what changed in a period, in plain terms, from the stored records
 * and their change history:
 *
 *  - newly licensed, and licences renewed (a new licence where one was held before)
 *  - new applications, and renewal applications (where a licence is already held)
 *  - licences that ended (gone from the council's list, unless replaced by a renewal)
 *  - applications that dropped off the list without a licence following
 *  - licence dates that changed
 *  - student accommodation: new schemes, changed details, schemes no longer listed
 *
 * Records loaded on the first run are history, so they never appear as new.
 */
final class ChangeReport
{
    public const SECTIONS = [
        'hmo'       => 'Houses in multiple occupation (HMOs)',
        'selective' => 'Other rented homes (selective licences)',
        'student'   => 'Purpose-built student accommodation',
    ];

    private const STUDENT_FIELDS = [
        'name' => 'name', 'bedspaces' => 'bed spaces', 'studios' => 'studios', 'clusters' => 'cluster rooms',
        'scheme_type' => 'type', 'academic_year' => 'year', 'provider' => 'operator', 'address' => 'address',
    ];

    public function __construct(private PDO $pdo, private ?array $coreArea = null)
    {
    }

    /**
     * @return list<array{section: string, what: string, address: string, detail: string, inside: bool, id: ?string, sort: string}>
     */
    public function between(string $start, string $end): array
    {
        $items = array_merge(
            $this->newLicences($start, $end),
            $this->endedLicences($start, $end),
            $this->changedDates($start, $end),
            $this->studentChanges($start, $end)
        );
        usort($items, static fn($a, $b) =>
            [array_search($a['section'], array_keys(self::SECTIONS), true), $a['sort'], $a['address']]
            <=> [array_search($b['section'], array_keys(self::SECTIONS), true), $b['sort'], $b['address']]);
        return $items;
    }

    private function newLicences(string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM licences WHERE backfilled = 0 AND removed_at IS NULL AND first_seen_at >= ? AND first_seen_at < ?'
        );
        $stmt->execute([$start, $end]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            if ($r['stage'] === 'licence') {
                $renewal = stripos((string) $r['register_type'], 'renewal') !== false || $this->hadLicenceBefore($r);
                $what = $renewal ? 'Licence renewed' : 'Newly licensed';
                $detail = Layers::label($r['kind'], $r['scheme'], 'licence')
                    . ($r['date_issued'] ? ', issued ' . self::date($r['date_issued']) : '')
                    . ($r['expiry_date'] ? ', runs to ' . self::date($r['expiry_date']) : '')
                    . ($r['max_occupants'] ? '. Up to ' . (int) $r['max_occupants'] . ' people' : '');
                $sort = $renewal ? '2' : '1';
            } else {
                $renewal = $this->hasCurrentLicence($r);
                $what = $renewal ? 'Renewal application' : 'New application';
                $detail = Layers::label($r['kind'], $r['scheme'], 'application')
                    . ($r['app_date'] ? ', received ' . self::date($r['app_date']) : '')
                    . ($renewal ? '. The property already holds a licence' : '');
                $sort = $renewal ? '4' : '3';
            }
            $items[] = $this->item($r, $what, $detail, $sort, GeoJsonExporter::id((string) $r['lkey']));
        }
        return $items;
    }

    private function endedLicences(string $start, string $end): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licences WHERE removed_at >= ? AND removed_at < ?');
        $stmt->execute([$start, $end]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            if ($this->replacedBy($r)) {
                continue;   // a renewal or a licence granted; reported as such
            }
            $removed = substr((string) $r['removed_at'], 0, 10);
            if ($r['stage'] === 'licence') {
                $expired = $r['expiry_date'] && $r['expiry_date'] <= $removed;
                $detail = Layers::label($r['kind'], $r['scheme'], 'licence')
                    . ($expired ? ', expired ' . self::date($r['expiry_date'])
                                : ' no longer on the council’s list' . ($r['expiry_date'] ? ' (it was due to run to ' . self::date($r['expiry_date']) . ')' : ''));
                $items[] = $this->item($r, 'Licence ended', $detail, '5', null);
            } else {
                $detail = Layers::label($r['kind'], $r['scheme'], 'application')
                    . ($r['app_date'] ? ' received ' . self::date($r['app_date']) : '')
                    . ' is no longer listed as pending, and no licence has appeared. It may have been withdrawn or refused.';
                $items[] = $this->item($r, 'Application closed', $detail, '6', null);
            }
        }
        return $items;
    }

    private function changedDates(string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.field, e.old_value, e.new_value, l.* FROM events e JOIN licences l ON l.lkey = e.rkey
             WHERE e.entity = 'licence' AND e.field IN ('date_issued', 'expiry_date')
               AND e.changed_at >= ? AND e.changed_at < ? AND l.removed_at IS NULL"
        );
        $stmt->execute([$start, $end]);
        $byKey = [];
        foreach ($stmt->fetchAll() as $r) {
            $label = $r['field'] === 'expiry_date' ? 'expiry date' : 'issue date';
            $byKey[$r['lkey']]['row'] = $r;
            $byKey[$r['lkey']]['parts'][] = $label . ' ' . ($r['old_value'] ? self::date($r['old_value']) : 'not set')
                . ' → ' . ($r['new_value'] ? self::date($r['new_value']) : 'not set');
        }
        $items = [];
        foreach ($byKey as $lkey => $c) {
            $detail = Layers::label($c['row']['kind'], $c['row']['scheme'], $c['row']['stage']) . ': ' . implode('; ', $c['parts']);
            $items[] = $this->item($c['row'], 'Licence dates changed', $detail, '7', GeoJsonExporter::id((string) $lkey));
        }
        return $items;
    }

    private function studentChanges(string $start, string $end): array
    {
        $items = [];
        $new = $this->pdo->prepare('SELECT * FROM student_schemes WHERE backfilled = 0 AND removed_at IS NULL AND first_seen_at >= ? AND first_seen_at < ?');
        $new->execute([$start, $end]);
        foreach ($new->fetchAll() as $s) {
            $items[] = $this->studentItem($s, 'New student accommodation scheme', self::studentSummary($s), '1');
        }

        $gone = $this->pdo->prepare('SELECT * FROM student_schemes WHERE removed_at >= ? AND removed_at < ?');
        $gone->execute([$start, $end]);
        foreach ($gone->fetchAll() as $s) {
            $items[] = $this->studentItem($s, 'No longer on the council’s list', self::studentSummary($s), '3', false);
        }

        $changed = $this->pdo->prepare(
            "SELECT e.field, e.old_value, e.new_value, s.* FROM events e JOIN student_schemes s ON s.scheme_id = e.rkey
             WHERE e.entity = 'student' AND e.changed_at >= ? AND e.changed_at < ? AND s.removed_at IS NULL
               AND (s.backfilled = 1 OR s.first_seen_at < ?)"
        );
        $changed->execute([$start, $end, $start]);
        $byKey = [];
        foreach ($changed->fetchAll() as $r) {
            if (!isset(self::STUDENT_FIELDS[$r['field']])) {
                continue;
            }
            $byKey[$r['scheme_id']]['row'] = $r;
            $byKey[$r['scheme_id']]['parts'][] = self::STUDENT_FIELDS[$r['field']] . ' ' . ($r['old_value'] ?? 'not given') . ' → ' . ($r['new_value'] ?? 'not given');
        }
        foreach ($byKey as $c) {
            $items[] = $this->studentItem($c['row'], 'Details changed', ucfirst(implode('; ', $c['parts'])), '2');
        }
        return $items;
    }

    /** A licence at the same address held before this one (current or since ended). */
    private function hadLicenceBefore(array $r): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM licences WHERE addr_key = ? AND kind = ? AND stage = 'licence' AND lkey <> ? AND first_seen_at < ?"
        );
        $stmt->execute([$r['addr_key'], $r['kind'], $r['lkey'], $r['first_seen_at']]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasCurrentLicence(array $r): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM licences WHERE addr_key = ? AND kind = ? AND stage = 'licence' AND removed_at IS NULL"
        );
        $stmt->execute([$r['addr_key'], $r['kind']]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Did a licence for the same address and kind appear since this record was first seen? */
    private function replacedBy(array $r): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM licences WHERE addr_key = ? AND kind = ? AND stage = 'licence' AND lkey <> ?
               AND removed_at IS NULL AND first_seen_at > ?"
        );
        $stmt->execute([$r['addr_key'], $r['kind'], $r['lkey'], $r['first_seen_at']]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function item(array $r, string $what, string $detail, string $sort, ?string $id): array
    {
        return [
            'section' => $r['kind'] === 'hmo' ? 'hmo' : 'selective',
            'what'    => $what,
            'address' => (string) $r['address'],
            'detail'  => $detail,
            'inside'  => $this->inside($r['lat'], $r['lng']),
            'id'      => $id,
            'sort'    => $sort,
        ];
    }

    private function studentItem(array $s, string $what, string $detail, string $sort, bool $linked = true): array
    {
        $name = trim((string) $s['name']);
        $address = trim((string) $s['address']);
        return [
            'section' => 'student',
            'what'    => $what,
            'address' => $name !== '' && stripos($address, $name) === false ? $name . ', ' . $address : ($address ?: $name),
            'detail'  => $detail,
            'inside'  => $this->inside($s['lat'], $s['lng']),
            'id'      => $linked ? 'pbsa-' . GeoJsonExporter::id((string) $s['scheme_id']) : null,
            'sort'    => $sort,
        ];
    }

    private static function studentSummary(array $s): string
    {
        $parts = [];
        if ($s['bedspaces']) {
            $parts[] = (int) $s['bedspaces'] . ' bed spaces';
        }
        if ($s['provider'] && !in_array(strtolower((string) $s['provider']), ['unknown', 'private owner'], true)) {
            $parts[] = 'run by ' . $s['provider'];
        }
        if ($s['academic_year'] && strtolower((string) $s['academic_year']) !== 'archive') {
            $parts[] = 'year ' . $s['academic_year'];
        }
        if ($s['planning_ref']) {
            $parts[] = 'planning application ' . $s['planning_ref'];
        }
        return $parts ? ucfirst(implode(', ', $parts)) : 'No details given';
    }

    private function inside(mixed $lat, mixed $lng): bool
    {
        return $this->coreArea === null || $lat === null || $lng === null
            || Geo::pointInGeoJson((float) $lng, (float) $lat, $this->coreArea);
    }

    /** "2026-11-02" -> "2 Nov 2026" */
    public static function date(?string $ymd): string
    {
        if (!$ymd) {
            return '';
        }
        $t = strtotime(substr($ymd, 0, 10) . ' 12:00 UTC');
        return gmdate('j M Y', $t);
    }
}
