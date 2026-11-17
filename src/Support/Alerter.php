<?php
declare(strict_types=1);

namespace Landlords\Support;

/**
 * Emails the site admin when something goes wrong. Uses PHP's mail(),
 * which IONOS shared hosting routes through its own mail server.
 */
class Alerter
{
    public function __construct(private ?string $to, private ?string $from)
    {
    }

    public function send(string $subject, string $body): bool
    {
        if (!$this->to) {
            return false;
        }
        $headers = [];
        if ($this->from) {
            $headers[] = 'From: LANDLORDS <' . $this->from . '>';
        }
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        return @mail($this->to, '[LANDLORDS] ' . $subject, $body, implode("\r\n", $headers));
    }
}
