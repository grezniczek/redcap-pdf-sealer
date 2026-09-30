<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use RuntimeException;
use Throwable;
use Vanderbilt\REDCap\Classes\Http\ResponseByteLimit;

/** One bounded RFC 3161 POST through REDCap's proxy/TLS-aware HTTP client. */
final class HttpsTimestampTransport
{
    public const MAX_RESPONSE_BYTES = 65_536;
    public const TIMEOUT_SECONDS = 10;

    public function __construct(
        #[\SensitiveParameter] private readonly string $endpoint,
        #[\SensitiveParameter] private readonly string $username = '',
        #[\SensitiveParameter] private readonly string $password = '',
        private readonly ?float $deadline = null,
    ) {
        $url = parse_url($endpoint);
        if (strlen($endpoint) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint)
            || !is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Timestamp endpoint must be an HTTPS URL without credentials or fragment');
        }
        if (strlen($username) > 256 || strlen($password) > 4096 || str_contains($username, ':')
            || preg_match('/[\x00-\x1f\x7f]/', $username . $password)
            || ($username === '' && $password !== '')) {
            throw new RuntimeException('Invalid timestamp authentication');
        }
    }

    public function __invoke(string $requestDer): string
    {
        if ($requestDer === '' || strlen($requestDer) > 4096) {
            throw new RuntimeException('Invalid timestamp request size');
        }
        if (!is_callable(['HttpClient', 'requestWithResponseLimit']) || !class_exists(ResponseByteLimit::class)) {
            throw new RuntimeException('REDCap bounded HTTP transport is unavailable');
        }
        $timeout = $this->deadline === null ? self::TIMEOUT_SECONDS
            : min(self::TIMEOUT_SECONDS, $this->deadline - hrtime(true) / 1e9);
        if ($timeout < 0.05) { throw new RuntimeException('Timestamp time budget exhausted'); }
        $options = [
            'body' => $requestDer,
            'headers' => ['Content-Type' => 'application/timestamp-query', 'Accept' => 'application/timestamp-reply',
                'Accept-Encoding' => 'identity'],
            'allow_redirects' => false, 'http_errors' => false, 'cookies' => false,
            'connect_timeout' => min(3, $timeout), 'timeout' => $timeout,
            'read_timeout' => $timeout, 'decode_content' => false,
        ];
        if ($this->username !== '') { $options['auth'] = [$this->username, $this->password, 'basic']; }
        try {
            // Inherit REDCap's TLS verification / configured CA bundle and proxy.
            // The helper's bounded sink also covers missing/false Content-Length.
            $response = \HttpClient::requestWithResponseLimit('POST', $this->endpoint, $options,
                ResponseByteLimit::fromRuntime(self::MAX_RESPONSE_BYTES));
            $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
            if ($response->getStatusCode() !== 200 || $type !== 'application/timestamp-reply'
                || !in_array(strtolower(trim($response->getHeaderLine('Content-Encoding'))), ['', 'identity'], true)) {
                throw new RuntimeException('Unexpected timestamp HTTP response');
            }
            $body = (string) $response->getBody();
            if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('Invalid timestamp response size');
            }
            return $body;
        } catch (Throwable) {
            // Do not retain the HTTP exception: its message/trace can contain URL
            // tokens, authentication headers, proxy credentials or response bytes.
            throw new RuntimeException('External timestamp HTTP request failed');
        }
    }

    public function __debugInfo(): array { return ['transport' => 'HTTPS RFC 3161', 'credentials' => '[redacted]']; }

    public function __serialize(): array { throw new RuntimeException('Timestamp credentials must not be serialized'); }
}
