<?php
declare(strict_types=1);

namespace Landlords;

use Landlords\Council\ArcGisClient;
use Landlords\Council\Layers;
use Landlords\Council\Records;
use Landlords\Export\GeoJsonExporter;
use Landlords\Registers\RegisterEnricher;
use Landlords\Storage\TrackedStore;
use PDO;
use RuntimeException;

/**
 * The daily job: fetch the licences, pending applications and student
 * accommodation in The Park (and the margin around it) from the council, store
 * changes, add details from the published registers, and rewrite the map's files.
 *
 * On any failure the database transaction is rolled back and the existing
 * map files are left untouched, so the site keeps showing the previous data.
 */
final class SyncJob
{
    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private ArcGisClient $council,
        private TrackedStore $licences,
        private TrackedStore $students,
        private GeoJsonExporter $exporter,
        private PDO $pdo,
        private string $areaName,
        private string $cacheDir,
        private float $minRatioOfKnown = 0.8,
        private float $bufferMetres = 0,
        private ?RegisterEnricher $registers = null
    ) {
    }

    /**
     * @param bool $refreshBoundary re-download the conservation area outline
     * @return array summary of the run
     */
    public function run(bool $refreshBoundary = false, ?string $nowUtc = null): array
    {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');
        $runId = $this->startRun($now);

        try {
            $boundary = $this->boundary($refreshBoundary);
            $this->exporter->writeBoundary($boundary['geojson']);
            $watchArea = $this->bufferMetres > 0 ? $this->watchArea($boundary, $refreshBoundary) : null;
            if ($watchArea !== false) {
                $this->exporter->writeWatchArea($watchArea);   // null removes it (no margin)
            }

            // ----- Licences and applications -----
            $records = [];
            $perLayer = [];
            $skipped = 0;
            foreach (Layers::licensing() as $layerId => $layer) {
                $missing = $this->council->missingFields($layerId, $layer['fields']);
                if ($missing) {
                    throw new RuntimeException("Council layer {$layerId} is missing fields: " . implode(', ', $missing));
                }
                $features = $this->council->fetchWithin($layerId, $boundary['esri'], $this->bufferMetres, $layer['fields']);
                $n = 0;
                foreach ($features as $feature) {
                    $record = Records::licence($feature, $layer);
                    if ($record === null) {
                        $skipped++;
                        continue;
                    }
                    if (!isset($records[$record['lkey']])) {
                        $records[$record['lkey']] = $record;
                        $n++;
                    }
                }
                $perLayer[] = Layers::label($layer['kind'], $layer['scheme'], $layer['stage']) . ': ' . $n;
                // A layer that suddenly comes back empty is an outage, not every licence ending at once.
                $held = $this->heldInLayer($layer);
                if ($n === 0 && $held >= 5) {
                    throw new RuntimeException(sprintf('Council layer %d returned nothing but we hold %d of its records; keeping existing data', $layerId, $held));
                }
            }
            $fetched = count($records);
            $this->note("Fetched {$fetched} licences and applications " . $this->describeArea() . ': ' . implode(', ', $perLayer));
            if ($skipped) {
                $this->note("Skipped {$skipped} records with no reference or address");
            }

            $known = $this->licences->countActive();
            if ($fetched === 0) {
                throw new RuntimeException('Council returned no licences; keeping existing data');
            }
            if ($known > 0 && $fetched < $known * $this->minRatioOfKnown) {
                throw new RuntimeException(sprintf(
                    'Council returned %d licences and applications but we hold %d; keeping existing data in case of a partial outage',
                    $fetched, $known
                ));
            }

            // ----- Student accommodation -----
            $missing = $this->council->missingFields(Layers::STUDENT_LAYER, Layers::STUDENT_FIELDS);
            if ($missing) {
                throw new RuntimeException('Council student accommodation layer is missing fields: ' . implode(', ', $missing));
            }
            $schemes = [];
            foreach ($this->council->fetchWithin(Layers::STUDENT_LAYER, $boundary['esri'], $this->bufferMetres, Layers::STUDENT_FIELDS) as $feature) {
                $s = Records::student($feature);
                if ($s !== null) {
                    $schemes[$s['scheme_id']] ??= $s;
                }
            }
            $knownSchemes = $this->students->countActive();
            if ($knownSchemes > 0 && count($schemes) === 0) {
                throw new RuntimeException("Council returned no student accommodation but we hold {$knownSchemes}; keeping existing data");
            }
            $this->note('Fetched ' . count($schemes) . ' student accommodation schemes');

            // ----- Store (one transaction for both) -----
            $firstLicences = $this->licences->isEmpty();
            $firstStudents = $this->students->isEmpty();
            $this->pdo->beginTransaction();
            try {
                $l = $this->licences->sync(array_values($records), $now, $firstLicences);
                $s = $this->students->sync(array_values($schemes), $now, $firstStudents);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
            if ($firstLicences) {
                $this->note('First run: everything already licensed is recorded as history and will not be emailed');
            }
            $this->note(sprintf('Licences: %d new, %d changed, %d removed, %d restored', $l['inserted'], $l['updated'], $l['removed'], $l['restored']));
            $this->note(sprintf('Student schemes: %d new, %d changed, %d removed', $s['inserted'], $s['updated'], $s['removed']));

            // ----- Register details (never fatal) -----
            if ($this->registers) {
                foreach ($this->registers->run() as $line) {
                    $this->note($line);
                }
            }

            $meta = $this->exporter->write(
                $this->licences->allActive('address'),
                $this->students->allActive('name'),
                $now, $boundary['geojson'], $this->bufferMetres
            );
            $this->note(sprintf('Wrote map data: %d licence and application pins, %d student schemes%s',
                $meta['licences'], $meta['students'], $meta['outside'] ? ", {$meta['outside']} in the " . self::metres($this->bufferMetres) . ' m margin' : ''));

            $summary = ['status' => 'ok', 'fetched' => $fetched + count($schemes),
                'inserted' => $l['inserted'] + $s['inserted'], 'updated' => $l['updated'] + $s['updated'],
                'removed' => $l['removed'] + $s['removed'], 'restored' => $l['restored'] + $s['restored'], 'log' => $this->log];
            $this->finishRun($runId, 'ok', $summary);
            return $summary;
        } catch (\Throwable $e) {
            $this->note('FAILED: ' . $e->getMessage());
            $this->finishRun($runId, 'failed', ['log' => $this->log]);
            throw $e;
        }
    }

    public function log(): array
    {
        return $this->log;
    }

    /** Use the cached boundary unless asked to refresh or none is cached yet. */
    private function boundary(bool $refresh): array
    {
        $file = $this->cacheDir . '/boundary-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($this->areaName)) . '.json';
        if (!$refresh && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && isset($cached['esri']['rings'], $cached['geojson'])) {
                return $cached;
            }
        }

        $boundary = $this->council->fetchBoundary($this->areaName);
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException("Cannot create cache directory {$this->cacheDir}");
        }
        file_put_contents($file, json_encode($boundary, JSON_UNESCAPED_SLASHES));
        $vertices = array_sum(array_map('count', $boundary['esri']['rings']));
        $this->note("Downloaded boundary for \"{$this->areaName}\" ({$vertices} points)");
        return $boundary;
    }

    /**
     * The widened outline for the map, cached like the boundary. Returns false if the
     * geometry service is unavailable: the sync carries on and the map keeps its last outline.
     */
    private function watchArea(array $boundary, bool $refresh): array|false
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($this->areaName));
        $file = $this->cacheDir . '/watch-area-' . $slug . '-' . self::metres($this->bufferMetres) . 'm.json';
        if (!$refresh && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && isset($cached['features'])) {
                return $cached;
            }
        }
        try {
            $outline = $this->council->bufferOutline($boundary['esri'], $this->bufferMetres);
        } catch (\Throwable $e) {
            $this->note('Could not draw the ' . self::metres($this->bufferMetres) . ' m outline (' . $e->getMessage() . '); the map keeps its previous outline');
            return false;
        }
        file_put_contents($file, json_encode($outline, JSON_UNESCAPED_SLASHES));
        $this->note('Drew the ' . self::metres($this->bufferMetres) . " m outline around \"{$this->areaName}\"");
        return $outline;
    }

    private function heldInLayer(array $layer): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM licences WHERE removed_at IS NULL AND kind = ? AND scheme = ? AND stage = ?');
        $stmt->execute([$layer['kind'], $layer['scheme'], $layer['stage']]);
        return (int) $stmt->fetchColumn();
    }

    private function describeArea(): string
    {
        return $this->bufferMetres > 0
            ? "inside \"{$this->areaName}\" or within " . self::metres($this->bufferMetres) . ' m of it'
            : "inside \"{$this->areaName}\"";
    }

    /** 50.0 -> "50", 12.5 -> "12.5" */
    public static function metres(float $m): string
    {
        return rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.');
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    private function startRun(string $now): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO sync_runs (started_at, status) VALUES (?, 'running')");
        $stmt->execute([$now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function finishRun(int $id, string $status, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sync_runs SET finished_at = ?, status = ?, fetched = ?, inserted = ?, updated = ?, removed = ?, message = ? WHERE id = ?'
        );
        $stmt->execute([
            gmdate('Y-m-d H:i:s'), $status,
            $summary['fetched'] ?? null, $summary['inserted'] ?? null,
            $summary['updated'] ?? null, $summary['removed'] ?? null,
            implode("\n", $summary['log'] ?? []), $id,
        ]);
    }
}
