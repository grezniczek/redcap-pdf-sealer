<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\PublicTrustRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FakeResult
{
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
}

$tempPaths = [];
$issuer = new CertificateIssuer(static function () use (&$tempPaths): string {
    $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_trust_test_');
    check(is_string($path), 'Cannot create test OpenSSL config');
    $tempPaths[] = $path;
    return $path;
});
try {
    $first = $issuer->createRoot('Earlier Institution');
    $second = $issuer->createRoot('Current Institution');
    $firstId = str_repeat('a', 32);
    $secondId = str_repeat('b', 32);
    $rows = [
        ['identity_id' => $secondId, 'certificate_der_b64' => base64_encode($second->certificateDer),
            'certificate_sha256' => hash('sha256', $second->certificateDer)],
        ['identity_id' => $firstId, 'certificate_der_b64' => base64_encode($first->certificateDer),
            'certificate_sha256' => hash('sha256', $first->certificateDer)],
    ];
    $settings = ['active_root_identity_id' => $secondId];
    $reader = new PrimaryLogReader((object) [], static function (string $sql, array $params) use (&$rows): FakeResult {
        check(str_contains($sql, 'ISNULL(project_id)') && !str_contains($sql, 'private_key_ciphertext'),
            'Public query did not exclude private key fields or project records');
        check($params === ['pki_identity', 'root'], 'Public query selected the wrong identity role');
        return new FakeResult($rows);
    });
    $settingReader = new PrimarySystemSettingReader((object) [],
        static fn (string $key): mixed => $settings[$key] ?? null);
    $repository = new PublicTrustRepository($reader, $settingReader);

    check($repository->activeRootId() === $secondId, 'Current root pointer was not read');
    $roots = $repository->roots();
    check(count($roots) === 2 && $roots[0]['id'] === $secondId && $roots[1]['id'] === $firstId,
        'Current and historical roots were not listed');
    check($roots[0]['der'] === $second->certificateDer && $roots[1]['der'] === $first->certificateDer,
        'Public root bytes changed');
    check(str_contains($roots[0]['subject'], 'Current Institution')
        && $roots[0]['valid_from'] < $roots[0]['valid_until'], 'Public metadata is invalid');

    $rows[0]['certificate_sha256'] = str_repeat('0', 64);
    try {
        $repository->roots();
        throw new RuntimeException('Tampered public root was accepted');
    } catch (RuntimeException $e) {
        check($e->getMessage() === 'Public root certificate digest mismatch', 'Unexpected tamper outcome');
    }
    $rows[0]['certificate_sha256'] = hash('sha256', $second->certificateDer);
    $rows[1]['identity_id'] = $secondId;
    try {
        $repository->roots();
        throw new RuntimeException('Duplicate root identity was accepted');
    } catch (RuntimeException $e) {
        check($e->getMessage() === 'Malformed public root record', 'Unexpected duplicate outcome');
    }
    echo "Public trust: public-only root listing, active pointer, and integrity checks passed.\n";
} finally {
    foreach ($tempPaths as $path) {
        @unlink($path);
    }
}
