<?php
declare(strict_types=1);

namespace Landlords\Support;

/**
 * Address helpers. The council's map layers and its spreadsheets write the same
 * address in slightly different ways ("11 - 13 Victoria Street" and
 * "11-13 Victoria Street, Nottingham"), so they are compared by a key that keeps
 * only letters and digits, without the town, plus the postcode.
 */
final class Address
{
    private const POSTCODE = '/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i';

    /** "NG7 1NX", or null. */
    public static function postcode(?string $address): ?string
    {
        if ($address !== null && preg_match(self::POSTCODE, $address, $m)) {
            return strtoupper($m[1] . ' ' . $m[2]);
        }
        return null;
    }

    /**
     * "Flat 2, 1 Balfour Road, Nottingham, NG7 1NY" -> "flat21balfourroad|NG71NY".
     *
     * @param string|list<string|null> $address one string, or address lines to join
     */
    public static function key(string|array $address): string
    {
        $text = is_array($address) ? implode(', ', array_filter($address, static fn($p) => $p !== null && trim((string) $p) !== '')) : $address;
        $postcode = self::postcode($text);
        $rest = $postcode !== null ? (string) preg_replace(self::POSTCODE, '', $text) : $text;
        $parts = array_filter(
            array_map(static fn($p) => trim($p), explode(',', $rest)),
            static fn($p) => $p !== '' && !preg_match('/^(nottingham( city)?|nottinghamshire)$/i', $p)
        );
        $core = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', implode(' ', $parts)));
        return $core . '|' . str_replace(' ', '', (string) $postcode);
    }

    /** "1 Clumber Road East, Nottingham, Nottingham City, NG7 1BD" -> "1 Clumber Road East, NG7 1BD" */
    public static function tidy(?string $address): string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $address)),
            static fn($p) => $p !== '' && !preg_match('/^nottingham( city)?$/i', $p));
        return $parts ? implode(', ', $parts) : 'Address not given';
    }

    /** Trim, collapse spaces, drop control characters; empty strings become null. */
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $s);
        $s = trim((string) preg_replace('/\s{2,}/', ' ', $s));
        return $s === '' ? null : $s;
    }
}
