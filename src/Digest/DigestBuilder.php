<?php
declare(strict_types=1);

namespace Landlords\Digest;

use Landlords\Mail\EmailLayout;
use Landlords\Mail\Message;
use Landlords\Support\Address;

/**
 * Writes the weekly email for one subscriber: the week's changes, grouped into
 * HMOs, other rented homes and student accommodation, each with a link to its
 * pin on the map.
 */
final class DigestBuilder
{
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                            'August', 'September', 'October', 'November', 'December'];

    public function __construct(
        private string $siteUrl,
        private float $bufferMetres = 0,
        private ?string $organisation = null
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * @param list<array> $items from ChangeReport::between()
     * @param string $weekTo the digest's end date, 'Y-m-d' (UK date)
     */
    public function message(array $items, string $weekTo, string $to, ?string $name, string $unsubscribeUrl, bool $test = false): Message
    {
        $n = count($items);
        $where = $this->bufferMetres > 0 ? 'in and around The Park' : 'in The Park';
        $subject = sprintf('LANDLORDS weekly: %d change%s to rented homes %s', $n, $n === 1 ? '' : 's', $where);
        if ($test) {
            $subject = '[TEST] ' . $subject;
        }
        $intro = sprintf('%d change%s to licensed rented homes and student accommodation %s appeared in the council’s data in the week to %s.',
            $n, $n === 1 ? '' : 's', $where, self::longDate($weekTo));

        $groups = [];
        foreach ($items as $item) {
            $groups[$item['section']][] = $item;
        }

        // ----- HTML -----
        $html = ($name ? '<p style="margin:0 0 12px">Hello ' . EmailLayout::e($name) . ',</p>' : '')
            . '<p style="margin:0 0 20px">' . EmailLayout::e($intro) . '</p>';
        if ($test) {
            $html = '<p style="margin:0 0 16px;padding:10px 12px;background:#fbe7b5;border-radius:6px;font-size:15px">'
                . 'This is a test. It shows what subscribers would receive; nothing was sent to them.</p>' . $html;
        }
        foreach (ChangeReport::SECTIONS as $section => $heading) {
            if (!empty($groups[$section])) {
                $html .= $this->sectionHtml($heading, $groups[$section]);
            }
        }
        $html .= '<p style="margin:20px 0 20px">' . EmailLayout::button($this->siteUrl . '/', 'Open the LANDLORDS map') . '</p>';

        $host = (string) parse_url($this->siteUrl, PHP_URL_HOST);
        $footer = 'You get this email because you signed up at ' . EmailLayout::link($this->siteUrl . '/', $host)
            . '. It comes on Mondays when something has changed.<br>'
            . EmailLayout::link($unsubscribeUrl, 'Unsubscribe') . ' &nbsp; ' . EmailLayout::link($this->siteUrl . '/privacy.php', 'Privacy notice')
            . '<br><br>Licensing data from Nottingham City Council’s public map service and registers. Pin positions are approximate; '
            . 'the council’s register is the official record.'
            . ($this->organisation ? '<br>Run by ' . EmailLayout::e($this->organisation) . '.' : '');

        // ----- Plain text -----
        $text = ($test ? "THIS IS A TEST. It shows what subscribers would receive; nothing was sent to them.\n\n" : '')
            . ($name ? "Hello {$name},\n\n" : '') . $intro . "\n\n";
        foreach (ChangeReport::SECTIONS as $section => $heading) {
            if (!empty($groups[$section])) {
                $text .= $this->sectionText($heading, $groups[$section]);
            }
        }
        $text .= "Map: {$this->siteUrl}/\n\n--\n"
            . "You get this email because you signed up at {$this->siteUrl}/. It comes on Mondays when something has changed.\n"
            . "Unsubscribe: {$unsubscribeUrl}\nPrivacy notice: {$this->siteUrl}/privacy.php\n"
            . "Licensing data from Nottingham City Council.\n";

        $headers = [
            'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'List-Id' => 'LANDLORDS weekly <weekly.' . $host . '>',
        ];

        return new Message($to, $subject, $text, EmailLayout::wrap($html, $footer, $intro), $name, $headers);
    }

    private function sectionHtml(string $heading, array $items): string
    {
        $out = '<h2 style="margin:24px 0 4px;font-size:19px;color:#1f3d34">' . EmailLayout::e($heading) . '</h2>';
        foreach ($items as $i) {
            $out .= '<div style="padding:12px 0;border-bottom:1px solid #d6dbd4">'
                . '<div style="font-size:14px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#8a4b1f">'
                    . EmailLayout::e($i['what']) . '</div>'
                . '<div style="font-size:18px;font-weight:700;line-height:1.3;margin:2px 0 4px">' . EmailLayout::e(Address::tidy($i['address']))
                    . ($i['inside'] ? '' : ' <span style="font-size:14px;font-weight:400;color:#56635e">(just outside The Park)</span>') . '</div>'
                . '<div style="font-size:16px;margin:0 0 6px">' . EmailLayout::e($i['detail']) . '</div>'
                . ($i['id'] ? '<div style="font-size:15px">' . EmailLayout::link($this->mapUrl($i['id']), 'See it on the map') . '</div>' : '')
                . '</div>';
        }
        return $out;
    }

    private function sectionText(string $heading, array $items): string
    {
        $out = strtoupper($heading) . "\n\n";
        foreach ($items as $i) {
            $out .= $i['what'] . ': ' . Address::tidy($i['address']) . ($i['inside'] ? '' : ' (just outside The Park)') . "\n"
                . $i['detail'] . "\n"
                . ($i['id'] ? 'Map: ' . $this->mapUrl($i['id']) . "\n" : '') . "\n";
        }
        return $out;
    }

    private function mapUrl(string $id): string
    {
        return $this->siteUrl . '/?p=' . rawurlencode($id);
    }

    public static function longDate(string $ymd): string
    {
        $t = strtotime($ymd . ' 12:00 UTC');
        return gmdate('l j ', $t) . self::MONTHS[(int) gmdate('n', $t) - 1];
    }
}
