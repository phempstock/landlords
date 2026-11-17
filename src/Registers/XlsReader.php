<?php
declare(strict_types=1);

namespace Landlords\Registers;

use RuntimeException;

/**
 * Reads the first worksheet of an old-style Excel .xls file (BIFF8 inside an OLE
 * compound file), which is how the council publishes its HMO register. Only what
 * that file needs: text and number cells. No external library, so nothing extra
 * has to be installed on the web host.
 */
final class XlsReader
{
    private const END = 0xFFFFFFFA;  // sector numbers at or above this are markers, not sectors

    /** @return list<list<string|float|null>> rows of cells, in order, gaps filled with null */
    public static function rows(string $bytes): array
    {
        $workbook = self::workbookStream($bytes);
        return self::firstSheet($workbook);
    }

    private static function workbookStream(string $b): string
    {
        if (strlen($b) < 512 || substr($b, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Not an Excel .xls file');
        }
        $u16 = static fn(int $o) => unpack('v', $b, $o)[1];
        $u32 = static fn(int $o) => unpack('V', $b, $o)[1];
        $sectorSize = 1 << $u16(30);
        $sectorAt = static fn(int $n) => 512 + $n * $sectorSize;

        // The FAT: sector list from the header (109 entries) plus any DIFAT sectors.
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $s = $u32(76 + $i * 4);
            if ($s < self::END) {
                $fatSectors[] = $s;
            }
        }
        $difat = $u32(68);
        $guard = 0;
        while ($difat < self::END && $guard++ < 1000) {
            for ($i = 0; $i < $sectorSize / 4 - 1; $i++) {
                $s = $u32($sectorAt($difat) + $i * 4);
                if ($s < self::END) {
                    $fatSectors[] = $s;
                }
            }
            $difat = $u32($sectorAt($difat) + $sectorSize - 4);
        }
        $fat = [];
        foreach ($fatSectors as $s) {
            $fat = array_merge($fat, array_values(unpack('V*', substr($b, $sectorAt($s), $sectorSize))));
        }

        $read = static function (int $start, int $size) use ($b, $fat, $sectorAt, $sectorSize): string {
            $out = '';
            $s = $start;
            $guard = 0;
            while ($s < self::END && $guard++ < 1_000_000 && isset($fat[$s])) {
                $out .= substr($b, $sectorAt($s), $sectorSize);
                $s = $fat[$s];
            }
            return $size >= 0 ? substr($out, 0, $size) : $out;
        };

        $dir = $read($u32(48), -1);
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $nameLength = unpack('v', $dir, $o + 64)[1];
            if ($nameLength < 2) {
                continue;
            }
            $name = mb_convert_encoding(substr($dir, $o, $nameLength - 2), 'UTF-8', 'UTF-16LE');
            if ($name === 'Workbook' || $name === 'Book') {
                $start = unpack('V', $dir, $o + 116)[1];
                $size = unpack('V', $dir, $o + 120)[1];
                if ($size < 4096) {
                    throw new RuntimeException('Workbook stream too small to be the register');
                }
                return $read($start, $size);
            }
        }
        throw new RuntimeException('No workbook found in the .xls file');
    }

    private static function firstSheet(string $w): array
    {
        // Split into records: [type, offset of data, length].
        $records = [];
        $len = strlen($w);
        for ($o = 0; $o + 4 <= $len;) {
            ['t' => $t, 'l' => $l] = unpack('vt/vl', $w, $o);
            $records[] = [$t, $o + 4, $l];
            $o += 4 + $l;
        }

        $sst = self::sharedStrings($w, $records);

        // BOUNDSHEET (0x85) gives each sheet's stream offset; take the first worksheet.
        $sheetPos = null;
        foreach ($records as [$t, $o]) {
            if ($t === 0x85) {
                $sheetPos = unpack('V', $w, $o)[1];
                break;
            }
        }
        if ($sheetPos === null) {
            throw new RuntimeException('No worksheet in the .xls file');
        }

        $grid = [];
        $started = false;
        foreach ($records as [$t, $o, $l]) {
            if (!$started) {
                $started = ($o - 4) === $sheetPos;
                continue;
            }
            if ($t === 0x0A) {               // EOF of the sheet
                break;
            }
            switch ($t) {
                case 0xFD:                   // LABELSST
                    ['r' => $r, 'c' => $c, 'i' => $i] = unpack('vr/vc/x2/Vi', $w, $o);
                    $grid[$r][$c] = $sst[$i] ?? null;
                    break;
                case 0x203:                  // NUMBER
                    ['r' => $r, 'c' => $c] = unpack('vr/vc', $w, $o);
                    $grid[$r][$c] = unpack('e', $w, $o + 6)[1];
                    break;
                case 0x27E:                  // RK
                    ['r' => $r, 'c' => $c] = unpack('vr/vc', $w, $o);
                    $grid[$r][$c] = self::rk(unpack('V', $w, $o + 6)[1]);
                    break;
                case 0xBD:                   // MULRK
                    ['r' => $r, 'c' => $c] = unpack('vr/vc', $w, $o);
                    for ($q = $o + 4; $q + 6 <= $o + $l - 2; $q += 6, $c++) {
                        $grid[$r][$c] = self::rk(unpack('V', $w, $q + 2)[1]);
                    }
                    break;
                case 0x204:                  // LABEL (older text cells)
                    ['r' => $r, 'c' => $c, 'n' => $n] = unpack('vr/vc/x2/vn', $w, $o);
                    $flags = ord($w[$o + 8]);
                    $grid[$r][$c] = $flags & 1
                        ? mb_convert_encoding(substr($w, $o + 9, $n * 2), 'UTF-8', 'UTF-16LE')
                        : mb_convert_encoding(substr($w, $o + 9, $n), 'UTF-8', 'Windows-1252');
                    break;
                case 0x06:                   // FORMULA: keep a cached number
                    ['r' => $r, 'c' => $c] = unpack('vr/vc', $w, $o);
                    if (substr($w, $o + 12, 2) !== "\xFF\xFF") {
                        $grid[$r][$c] = unpack('e', $w, $o + 6)[1];
                    }
                    break;
            }
        }

        ksort($grid);
        $rows = [];
        foreach ($grid as $cells) {
            $max = max(array_keys($cells));
            $row = [];
            for ($c = 0; $c <= $max; $c++) {
                $row[] = $cells[$c] ?? null;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** The shared string table (SST, 0xFC) and its CONTINUE (0x3C) records. */
    private static function sharedStrings(string $w, array $records): array
    {
        $first = null;
        foreach ($records as $i => [$t]) {
            if ($t === 0xFC) {
                $first = $i;
                break;
            }
        }
        if ($first === null) {
            return [];
        }
        $segments = [[$records[$first][1], $records[$first][2]]];
        for ($k = $first + 1; isset($records[$k]) && $records[$k][0] === 0x3C; $k++) {
            $segments[] = [$records[$k][1], $records[$k][2]];
        }

        $seg = 0;
        $p = $segments[0][0];
        $end = $p + $segments[0][1];
        $next = static function () use (&$seg, &$p, &$end, $segments): void {
            $seg++;
            if (!isset($segments[$seg])) {
                throw new RuntimeException('Shared string table is cut short');
            }
            [$p, $l] = $segments[$seg];
            $end = $p + $l;
        };

        $total = unpack('V', $w, $p + 4)[1];
        $p += 8;
        $strings = [];
        for ($n = 0; $n < $total; $n++) {
            if ($p >= $end) {
                $next();
            }
            $chars = unpack('v', $w, $p)[1];
            $p += 2;
            $flags = ord($w[$p++]);
            $rich = $ext = 0;
            if ($flags & 0x08) {
                $rich = unpack('v', $w, $p)[1];
                $p += 2;
            }
            if ($flags & 0x04) {
                $ext = unpack('V', $w, $p)[1];
                $p += 4;
            }
            $s = '';
            while ($chars > 0) {
                if ($p >= $end) {
                    $next();
                    $flags = ord($w[$p++]);   // each continuation restates the character width
                }
                $wide = $flags & 0x01;
                $take = min($chars, intdiv($end - $p, $wide ? 2 : 1));
                $bytes = substr($w, $p, $take * ($wide ? 2 : 1));
                $s .= $wide ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
                $p += strlen($bytes);
                $chars -= $take;
            }
            $skip = $rich * 4 + $ext;
            while ($skip > 0) {
                if ($p >= $end) {
                    $next();
                }
                $step = min($skip, $end - $p);
                $p += $step;
                $skip -= $step;
            }
            $strings[] = $s;
        }
        return $strings;
    }

    /** RK: a compressed number (30-bit integer or the top of a double, optionally ÷100). */
    private static function rk(int $v): float
    {
        if ($v & 0x02) {
            $n = (float) ($v >> 2);
            if ($v & 0x80000000) {
                $n -= 1 << 30;   // sign-extend the 30-bit integer
            }
        } else {
            $n = unpack('e', pack('V2', 0, $v & 0xFFFFFFFC))[1];
        }
        return ($v & 0x01) ? $n / 100 : $n;
    }
}
