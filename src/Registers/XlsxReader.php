<?php
declare(strict_types=1);

namespace Landlords\Registers;

use RuntimeException;
use XMLReader;

/**
 * Reads the first worksheet of an Excel .xlsx file (a zip of XML files), which is
 * how the council publishes its selective licensing register. Unzips with PHP's
 * built-in zlib, so the zip extension isn't needed on the web host.
 */
final class XlsxReader
{
    /** @return list<list<string|null>> rows of cell values as text, gaps filled with null */
    public static function rows(string $bytes): array
    {
        $files = self::unzip($bytes, ['xl/sharedStrings.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels']);
        $sheetPath = self::firstSheetPath($files);
        $sheet = self::unzip($bytes, [$sheetPath])[$sheetPath] ?? null;
        if ($sheet === null) {
            throw new RuntimeException('No worksheet in the .xlsx file');
        }
        $strings = isset($files['xl/sharedStrings.xml']) ? self::sharedStrings($files['xl/sharedStrings.xml']) : [];
        return self::sheetRows($sheet, $strings);
    }

    /**
     * The named entries of a zip archive, read from its central directory.
     *
     * @param list<string> $wanted
     * @return array<string, string>
     */
    private static function unzip(string $b, array $wanted): array
    {
        $eocd = strrpos($b, "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException('Not an .xlsx (zip) file');
        }
        ['n' => $count, 'off' => $cd] = unpack('x10/vn/x4/Voff', $b, $eocd);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            if (substr($b, $cd, 4) !== "PK\x01\x02") {
                throw new RuntimeException('Damaged zip directory');
            }
            $h = unpack('x10/vmethod/x8/Vcsize/Vusize/vnlen/vxlen/vclen/x8/Vlocal', $b, $cd);
            $name = substr($b, $cd + 46, $h['nlen']);
            $cd += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
            if (!in_array($name, $wanted, true)) {
                continue;
            }
            $local = unpack('vnlen/vxlen', $b, $h['local'] + 26);
            $data = substr($b, $h['local'] + 30 + $local['nlen'] + $local['xlen'], $h['csize']);
            if ($h['method'] === 8) {
                $data = gzinflate($data);
                if ($data === false) {
                    throw new RuntimeException("Could not unpack {$name}");
                }
            } elseif ($h['method'] !== 0) {
                throw new RuntimeException("Unsupported compression in {$name}");
            }
            $out[$name] = $data;
        }
        return $out;
    }

    private static function firstSheetPath(array $files): string
    {
        $workbook = $files['xl/workbook.xml'] ?? '';
        $rels = $files['xl/_rels/workbook.xml.rels'] ?? '';
        if (preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $m)) {
            preg_match_all('/<Relationship\b[^>]*>/', $rels, $all);
            foreach ($all[0] as $tag) {
                if (preg_match('/\bId="([^"]+)"/', $tag, $id) && $id[1] === $m[1] && preg_match('/\bTarget="([^"]+)"/', $tag, $t)) {
                    $target = ltrim($t[1], '/');
                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private static function sharedStrings(string $xml): array
    {
        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $strings = [];
        $current = null;
        $inPhonetic = false;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si') {
                    $current = '';
                } elseif ($r->localName === 'rPh') {
                    $inPhonetic = true;
                } elseif ($r->localName === 't' && $current !== null && !$inPhonetic) {
                    $current .= $r->readString();
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                if ($r->localName === 'si') {
                    $strings[] = (string) $current;
                    $current = null;
                } elseif ($r->localName === 'rPh') {
                    $inPhonetic = false;
                }
            }
        }
        $r->close();
        return $strings;
    }

    private static function sheetRows(string $xml, array $strings): array
    {
        $r = new XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $rows = [];
        $row = null;
        $col = 0;
        $type = null;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
                $row = [];
                $col = 0;
                if ($r->isEmptyElement) {
                    $rows[] = [];
                    $row = null;
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row') {
                $rows[] = $row ?? [];
                $row = null;
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'c' && $row !== null) {
                $ref = $r->getAttribute('r');
                $col = $ref ? self::column($ref) : count($row);
                $type = $r->getAttribute('t');
                if ($r->isEmptyElement) {
                    continue;
                }
                $value = null;
                $depth = $r->depth;
                while ($r->read() && !($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'c' && $r->depth === $depth)) {
                    if ($r->nodeType === XMLReader::ELEMENT && ($r->localName === 'v' || $r->localName === 't')) {
                        $value = ($value ?? '') . $r->readString();
                    }
                }
                if ($value !== null && $type === 's') {
                    $value = $strings[(int) $value] ?? null;
                } elseif ($value !== null && $type === 'b') {
                    $value = $value === '1' ? 'TRUE' : 'FALSE';
                }
                for ($c = count($row); $c < $col; $c++) {
                    $row[] = null;
                }
                $row[$col] = $value;
            }
        }
        $r->close();
        return array_map(static fn(array $cells) => array_values($cells), $rows);
    }

    /** "C12" -> 2 */
    private static function column(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Z]/i', '', $ref));
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
