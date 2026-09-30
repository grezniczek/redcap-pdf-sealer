<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Timestamp\HttpsTimestampTransport;
use Vanderbilt\REDCap\Classes\Http\ResponseByteLimit;

require dirname(__DIR__) . '/autoload.php';
// Exercise the transport contract without a live endpoint, credentials or REDCap bootstrap.
$core = getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase';
require $core . '/Classes/Http/ResponseByteLimit.php';

function transportCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function transportReject(callable $work): Throwable {
    try { $work(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Expected transport rejection');
}
final class HttpClient {
    public static array $calls = [];
    public static mixed $response;
    public static function requestWithResponseLimit(string $method, string $uri, array $options, ResponseByteLimit $limit): object {
        self::$calls[] = [$method, $uri, $options, $limit->getBytes()];
        if (self::$response instanceof Throwable) { throw self::$response; }
        return self::$response;
    }
}
function response(int $status = 200, string $type = 'application/timestamp-reply', string $body = 'DER', string $encoding = ''): object {
    return new class($status, $type, $body, $encoding) {
        public function __construct(private int $status, private string $type, private string $body, private string $encoding) {}
        public function getStatusCode(): int { return $this->status; }
        public function getHeaderLine(string $name): string { return $name === 'Content-Type' ? $this->type : $this->encoding; }
        public function getBody(): string { return $this->body; }
    };
}

foreach (['http://tsa.example/', 'file:///etc/passwd', 'https://u:p@tsa.example/', 'https://tsa.example/#fragment',
    "https://tsa.example/\r\nx: header", 'https://tsa.example/\\evil', 'https:///missing', 'https://tsa.example:99999/'] as $url) {
    transportReject(fn() => new HttpsTimestampTransport($url));
}
transportReject(fn() => new HttpsTimestampTransport('https://tsa.example/', 'user:name', 'password'));
transportReject(fn() => new HttpsTimestampTransport('https://tsa.example/', '', 'password'));
transportReject(fn() => new HttpsTimestampTransport('https://tsa.example/', 'user', "password\r\n"));
// An institution-hosted source is permitted, with TLS verification still required.
new HttpsTimestampTransport('https://tsa.intranet:8443/stamp');
$transport = new HttpsTimestampTransport('https://tsa.example/stamp?token=secret', 'tsa-user', 'tsa-password');
HttpClient::$response = response();
transportCheck($transport('query') === 'DER', 'Response bytes changed');
[$method, $uri, $options, $limit] = HttpClient::$calls[0];
transportCheck($method === 'POST' && $options['body'] === 'query', 'Incorrect method/body');
transportCheck($options['headers']['Content-Type'] === 'application/timestamp-query'
    && $options['headers']['Accept'] === 'application/timestamp-reply', 'Incorrect content negotiation');
transportCheck($options['allow_redirects'] === false && $options['http_errors'] === false
    && $options['decode_content'] === false && $options['cookies'] === false, 'Unsafe HTTP options');
transportCheck(!array_key_exists('verify', $options), 'REDCap TLS configuration overridden');
transportCheck($options['connect_timeout'] === 3 && $options['timeout'] === 10 && $limit > 0 && $limit <= 65536,
    'Missing request bounds');
transportCheck($options['auth'] === ['tsa-user', 'tsa-password', 'basic'], 'Authentication not supplied');
HttpClient::$response = response(type: 'Application/Timestamp-Reply; charset=binary');
transportCheck($transport('query') === 'DER', 'Valid content type rejected');
foreach ([response(302), response(401), response(500), response(type: 'text/html'), response(body: ''),
    response(body: str_repeat('x', 65537)), response(encoding: 'gzip'),
    new RuntimeException('Network error with token=secret and tsa-password')] as $response) {
    HttpClient::$response = $response;
    $e = transportReject(fn() => $transport('query'));
    transportCheck($e->getMessage() === 'External timestamp HTTP request failed' && $e->getPrevious() === null,
        'Transport error leaks underlying details');
}
transportReject(fn() => $transport(str_repeat('x', 4097)));
// Remaining operation budget shortens all HTTP timeouts; an exhausted budget
// cannot start a network request.
HttpClient::$response = response();
$bounded = new HttpsTimestampTransport('https://tsa.example/', deadline: hrtime(true) / 1e9 + 0.5);
$bounded('query');
$budgetOptions = end(HttpClient::$calls)[2];
transportCheck($budgetOptions['timeout'] > 0 && $budgetOptions['timeout'] <= 0.5
    && $budgetOptions['connect_timeout'] === $budgetOptions['timeout']
    && $budgetOptions['read_timeout'] === $budgetOptions['timeout'], 'Remaining deadline not applied');
$count = count(HttpClient::$calls);
transportReject(fn() => (new HttpsTimestampTransport('https://tsa.example/', deadline: hrtime(true) / 1e9 - 1))('query'));
transportCheck(count(HttpClient::$calls) === $count, 'Expired budget contacted service');
transportReject(fn() => serialize($transport));
ob_start(); var_dump($transport); $debug = ob_get_clean();
transportCheck(!str_contains($debug, 'secret') && !str_contains($debug, 'tsa-password'), 'Debug output leaks secrets');
echo "HTTPS timestamp request bounds, response contract, rejection and error redaction checks passed.\n";
