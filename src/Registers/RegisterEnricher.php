<?php
declare(strict_types=1);

namespace Landlords\Registers;

use Landlords\Http\HttpClient;
use Landlords\Support\Address;
use PDO;
use RuntimeException;

/**
 * Adds details from the council's published registers to the licences we hold:
 *  - HMO register (.xls, monthly): maximum occupants and households, and whether
 *    the licence followed a new or a renewal application. It has no licence
 *    reference, so rows are matched by address.
 *  - Selective licensing register (.xlsx): the property type (house or flat),
 *    matched exactly by licence reference.
 *
 * The file names change with each edition, so the current link is read from the
 * council's web page. A file is only downloaded when its link changes; the
 * parsed rows are cached. Failure here never stops the sync: the map simply
 * keeps the details it already has.
 */
final class RegisterEnricher
{
    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private HttpClient $http,
        private PDO $pdo,
        private string $cacheDir,
        private ?string $hmoPageUrl,
        private ?string $selectivePageUrl
    ) {
    }

    /** @return list<string> */
    public function run(): array
    {
        $this->log = [];
        if ($this->hmoPageUrl) {
            try {
                $rows = $this->register('hmo', $this->hmoPageUrl, '/href="([^"]*hmo[^"]*register[^"]*\.xlsx?)"/i');
                $this->applyHmo($rows);
            } catch (\Throwable $e) {
                $this->log[] = 'HMO register skipped: ' . $e->getMessage();
            }
        }
        if ($this->selectivePageUrl) {
            try {
                $rows = $this->register('selective', $this->selectivePageUrl, '/href="([^"]*(?:sl|selective)[^"]*register[^"]*\.xlsx?)"/i');
                $this->applySelective($rows);
            } catch (\Throwable $e) {
                $this->log[] = 'Selective register skipped: ' . $e->getMessage();
            }
        }
        return $this->log;
    }

    /**
     * The register's rows as header => value arrays, from the cache when the link is unchanged.
     *
     * @return array{url: string, rows: list<array<string, mixed>>}
     */
    private function register(string $name, string $pageUrl, string $linkPattern): array
    {
        $page = $this->http->getRaw($pageUrl);
        if (!preg_match($linkPattern, $page, $m)) {
            throw new RuntimeException('no register link found on the council page');
        }
        $url = self::absolute(html_entity_decode($m[1]), $pageUrl);
        $cacheFile = $this->cacheDir . "/register-{$name}.json";
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        if (is_array($cached) && ($cached['url'] ?? null) === $url && is_array($cached['rows'] ?? null)) {
            return $cached;
        }

        $bytes = $this->http->getRaw($url);
        $grid = str_ends_with(strtolower(strtok($url, '?')), '.xlsx') ? XlsxReader::rows($bytes) : XlsReader::rows($bytes);
        $rows = self::withHeaders($grid);
        if (count($rows) < 10) {
            throw new RuntimeException('the register file has only ' . count($rows) . ' rows');
        }
        $data = ['url' => $url, 'file' => basename(strtok($url, '?')), 'rows' => $rows, 'downloaded_at' => gmdate('Y-m-d H:i:s')];
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->log[] = sprintf('Downloaded the %s register %s (%d rows)', $name === 'hmo' ? 'HMO' : 'selective licensing', $data['file'], count($rows));
        return $data;
    }

    /**
     * Find the header row (the first row with at least four text cells, one of which
     * mentions an address) and turn the rows below it into arrays keyed by
     * lower-case header names.
     */
    public static function withHeaders(array $grid): array
    {
        foreach ($grid as $i => $row) {
            $texts = array_filter($row, static fn($v) => is_string($v) && trim($v) !== '' && !is_numeric($v));
            $joined = strtolower(implode(' ', $texts));
            if (count($texts) >= 4 && str_contains($joined, 'address')) {
                $headers = array_map(static fn($h) => strtolower(trim((string) $h)), $row);
                $out = [];
                foreach (array_slice($grid, $i + 1) as $r) {
                    if (!array_filter($r, static fn($v) => $v !== null && trim((string) $v) !== '')) {
                        continue;
                    }
                    $item = [];
                    foreach ($headers as $c => $h) {
                        if ($h !== '') {
                            $item[$h] = isset($r[$c]) && is_string($r[$c]) ? trim($r[$c]) : ($r[$c] ?? null);
                        }
                    }
                    $out[] = $item;
                }
                return $out;
            }
        }
        throw new RuntimeException('could not find the column headings');
    }

    /** @param array{rows: list<array<string, mixed>>} $register */
    private function applyHmo(array $register): void
    {
        // Index register rows by address key; also by postcode for the looser match.
        $byKey = [];
        $byPostcode = [];
        foreach ($register['rows'] as $row) {
            $lines = [];
            foreach (['address1', 'address2', 'address3', 'address4'] as $f) {
                $lines[] = isset($row[$f]) ? (string) $row[$f] : null;
            }
            $postcode = isset($row['address5']) ? (string) $row['address5'] : null;
            $key = Address::key(array_merge($lines, [$postcode]));
            $entry = [
                'occupants'  => self::int($row['maximum-occupants'] ?? null),
                'households' => self::int($row['maximum-households'] ?? null),
                'type'       => Address::text($row['additional/mandatory'] ?? null),
                'key'        => $key,
            ];
            $byKey[$key] = $entry;
            [, $pc] = explode('|', $key);
            if ($pc !== '') {
                $byPostcode[$pc][] = $entry;
            }
        }

        $licences = $this->pdo->query(
            "SELECT lkey, addr_key FROM licences WHERE kind = 'hmo' AND stage = 'licence' AND removed_at IS NULL"
        )->fetchAll();
        $update = $this->pdo->prepare('UPDATE licences SET max_occupants = ?, max_households = ?, register_type = ? WHERE lkey = ?');
        $matched = 0;
        foreach ($licences as $l) {
            $entry = $byKey[$l['addr_key']] ?? self::looseMatch($l['addr_key'], $byPostcode);
            if ($entry) {
                $update->execute([$entry['occupants'], $entry['households'], $entry['type'], $l['lkey']]);
                $matched++;
            }
        }
        $this->log[] = sprintf('HMO register: details added to %d of %d licensed HMOs', $matched, count($licences));
    }

    /**
     * Same postcode, and one address is the other with something extra (for example
     * "Flat 1, 11-15 Priory Court" and "11-15 Priory Court"); only when that leaves
     * exactly one candidate.
     */
    private static function looseMatch(string $key, array $byPostcode): ?array
    {
        [$core, $pc] = explode('|', $key);
        if ($pc === '' || strlen($core) < 8 || empty($byPostcode[$pc])) {
            return null;
        }
        $hits = array_values(array_filter($byPostcode[$pc], static function (array $e) use ($core) {
            [$other] = explode('|', $e['key']);
            return strlen($other) >= 8 && (str_ends_with($core, $other) || str_ends_with($other, $core));
        }));
        return count($hits) === 1 ? $hits[0] : null;
    }

    /** @param array{rows: list<array<string, mixed>>} $register */
    private function applySelective(array $register): void
    {
        $byRef = [];
        foreach ($register['rows'] as $row) {
            $ref = Address::text($row['our reference'] ?? null);
            if ($ref !== null) {
                $byRef[$ref] = Address::text($row['property usage description'] ?? null);
            }
        }
        $licences = $this->pdo->query(
            "SELECT lkey, ref FROM licences WHERE kind = 'selective' AND stage = 'licence' AND removed_at IS NULL"
        )->fetchAll();
        $update = $this->pdo->prepare('UPDATE licences SET property_type = ? WHERE lkey = ?');
        $matched = 0;
        foreach ($licences as $l) {
            if (array_key_exists($l['ref'], $byRef)) {
                $update->execute([$byRef[$l['ref']], $l['lkey']]);
                $matched++;
            }
        }
        $this->log[] = sprintf('Selective register: property type added to %d of %d selective licences', $matched, count($licences));
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private static function absolute(string $href, string $pageUrl): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($pageUrl);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
        return $origin . $dir . '/' . $href;
    }
}
