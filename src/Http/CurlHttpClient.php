<?php
declare(strict_types=1);

namespace Landlords\Http;

use RuntimeException;

/**
 * HTTP client built on PHP's curl extension (available on IONOS shared hosting).
 * Retries transient failures (network errors, 5xx, 429) with a short back-off.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private int $timeoutSeconds = 60,
        private int $maxAttempts = 3,
        private string $userAgent = 'LANDLORDS rented homes map (landlords.npra.co.uk)'
    ) {
    }

    public function getJson(string $url, array $params = []): array
    {
        $full = $params ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params) : $url;
        return (array) $this->request($full, null);
    }

    public function postJson(string $url, array $params): array
    {
        return (array) $this->request($url, http_build_query($params));
    }

    public function getRaw(string $url): string
    {
        return $this->request($url, null, false);
    }

    private function request(string $url, ?string $body, bool $json = true): array|string
    {
        $lastError = 'unknown error';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => [$json ? 'Accept: application/json' : 'Accept: */*'],
                CURLOPT_MAXFILESIZE    => 50 * 1024 * 1024,
                CURLOPT_ENCODING       => '', // accept gzip
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);

            if ($response === false) {
                $lastError = "network error: {$curlError}";
            } elseif ($status >= 500 || $status === 429) {
                $lastError = "HTTP {$status}";
            } elseif ($status >= 400) {
                throw new RuntimeException("HTTP {$status} from {$this->describe($url)}");
            } elseif (!$json) {
                return (string) $response;
            } else {
                $decoded = json_decode((string) $response, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException("Response from {$this->describe($url)} was not JSON");
                }
                return $decoded;
            }

            if ($attempt < $this->maxAttempts) {
                sleep(5 * $attempt);
            }
        }
        throw new RuntimeException("Request to {$this->describe($url)} failed after {$this->maxAttempts} attempts ({$lastError})");
    }

    private function describe(string $url): string
    {
        return strtok($url, '?') ?: $url;
    }
}
