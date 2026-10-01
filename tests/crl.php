<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Ltv\Crl;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\CrlIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\CrlPublicationService;
use DE\RUB\PDFSealerExternalModule\Pki\CrlRepository;
use DE\RUB\PDFSealerExternalModule\Pki\GeneratedIdentity;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\PublicCrlService;
use DE\RUB\PDFSealerExternalModule\Pki\PublicTrustRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/CertificateSerials.php';
require __DIR__ . '/support/pdf_seal_checks.php';
require __DIR__ . '/support/root_certificate_probe.php';

function encrypt(string $value): string { return base64_encode('test:' . $value); }
function decrypt(string $value): string|false {
    $decoded = base64_decode($value, true);
    return is_string($decoded) && str_starts_with($decoded, 'test:') ? substr($decoded, 5) : false;
}
function expectFailure(callable $callback, string $message): void {
    try { $callback(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
}
final class CrlTestResult {
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_row(): ?array { return array_shift($this->rows); }
}
final class CrlTestFramework {
    public array $settings = [];
    public array $logs = [];
    public bool $failAudit = false;
    public function getSystemSetting(string $key): mixed { return $this->settings[$key] ?? null; }
    public function setSystemSetting(string $key, mixed $value): void { $this->settings[$key] = $value; }
    public function log(string $message, array $parameters): int {
        checkSeal($parameters['project_id'] === null && $parameters['record'] === '', 'CRL data retained project/clinical context');
        if ($this->failAudit && $message === 'pki_crl_publication') { throw new RuntimeException('Test audit failure'); }
        $id = count($this->logs) + 1;
        $this->logs[] = ['log_id' => $id, 'message' => $message] + array_filter($parameters, static fn($v) => $v !== null);
        return $id;
    }
    public function queryLogs(string $sql, array $parameters): CrlTestResult {
        checkSeal(str_contains($sql, 'ISNULL(project_id)'), 'CRL identity query not system-scoped');
        $rows = array_filter(array_reverse($this->logs), static function($row) use ($sql, $parameters) {
            if ($row['message'] !== $parameters[0]) { return false; }
            $index = 1;
            foreach (['identity_id', 'identity_role'] as $key) {
                if (str_contains($sql, $key . ' = ?') && ($row[$key] ?? null) !== $parameters[$index++]) { return false; }
            }
            return true;
        });
        return new CrlTestResult(array_values($rows));
    }
}

$paths = [];
$temp = static function() use (&$paths): string {
    $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_crl_test_');
    checkSeal(is_string($path), 'Test temp file unavailable');
    $paths[] = $path;
    return $path;
};
try {
    $surveyUrl = 'https://redcap.example/surveys/';
    $issuer = new CertificateIssuer($temp, [\PDFSealerTests\CertificateSerials::class, 'reserve'], $surveyUrl);
    $root = $issuer->createRoot('CRL Test');
    $leaf = $issuer->createProject('CRL Test', $issuer->newProjectUuid(), $root);
    $tsa = $issuer->createTsa('CRL Test', $root);
    $url = CrlIssuer::url($surveyUrl, $root->certificateDer);
    foreach ([$leaf, $tsa] as $identity) {
        $info = openssl_x509_parse(Certificate::derToPem($identity->certificateDer));
        checkSeal(str_contains($info['extensions']['crlDistributionPoints'] ?? '', $url), 'Leaf is missing the public CRL URL');
    }
    foreach (["https://host/surveys/\nkeyUsage=none", 'https://host/$ENV/test/', 'https://user@host/surveys/',
        'https://host/surveys/?token=secret', 'https://host/surveys/#fragment', 'file:///tmp/'] as $badUrl) {
        expectFailure(fn() => CrlIssuer::url($badUrl, $root->certificateDer), 'Unsafe CRL configuration URL accepted');
    }
    $now = time();
    $builder = new CrlIssuer();
    $empty = $builder->issue($root, 1, $now);
    $der = $builder->verify($root->certificateDer, $empty);
    $validator = new Crl(maxAge: 0, clockSkew: 0);
    $validator->validate($der, $root->certificateDer, $leaf->certificateDer, $now);
    $pemPath = $temp(); $crlPath = $temp(); $leafPath = $temp();
    file_put_contents($pemPath, Certificate::derToPem($root->certificateDer));
    file_put_contents($crlPath, $der);
    file_put_contents($leafPath, Certificate::derToPem($leaf->certificateDer));
    $verified = runSealCommand(['openssl', 'crl', '-inform', 'DER', '-in', $crlPath, '-noout', '-verify', '-CAfile', $pemPath]);
    checkSeal($verified[0] === 0 && str_contains($verified[1], 'verify OK'), 'OpenSSL rejected the empty CRL');
    $healthy = runSealCommand(['openssl', 'verify', '-crl_check', '-CRLfile', $crlPath, '-CAfile', $pemPath, $leafPath]);
    checkSeal($healthy[0] === 0, 'OpenSSL CRL checking rejected a nonrevoked certificate');
    $text = runSealCommand(['openssl', 'crl', '-inform', 'DER', '-in', $crlPath, '-noout', '-text']);
    checkSeal(str_contains($text[1], 'X509v3 CRL Number') && str_contains($text[1], 'X509v3 Authority Key Identifier')
        && str_contains($text[1], 'No Revoked Certificates'), 'Missing v2 CRL extensions/empty-list encoding');

    $serial = strtolower(ltrim(openssl_x509_parse(Certificate::derToPem($leaf->certificateDer))['serialNumberHex'], '0'));
    $entry = ['serial_hex' => $serial, 'revoked_at' => $now, 'reason' => 1];
    $revoked = $builder->issue($root, 2, $now, [$entry]);
    file_put_contents($crlPath, base64_decode($revoked['der_b64'], true));
    $rejected = runSealCommand(['openssl', 'verify', '-crl_check', '-CRLfile', $crlPath, '-CAfile', $pemPath, $leafPath]);
    checkSeal($rejected[0] !== 0 && str_contains($rejected[1], 'certificate revoked'),
        'OpenSSL did not recognize the exact revoked serial (including 128-bit serials)');
    $reason = runSealCommand(['openssl', 'crl', '-inform', 'DER', '-in', $crlPath, '-noout', '-text']);
    checkSeal(str_contains($reason[1], 'Key Compromise'), 'OpenSSL did not recognize the revocation reason');
    $tampered = $revoked; $tampered['entries'] = [];
    expectFailure(fn() => $builder->verify($root->certificateDer, $tampered), 'Cache metadata could erase revoked entries');
    $tampered = $empty; $bytes = base64_decode($empty['der_b64']); $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
    $tampered['der_b64'] = base64_encode($bytes);
    expectFailure(fn() => $builder->verify($root->certificateDer, $tampered), 'Tampered CRL signature accepted');

    $framework = new CrlTestFramework();
    $protector = new SecretProtector();
    $reader = new PrimaryLogReader($framework, [$framework, 'queryLogs']);
    $settings = new PrimarySystemSettingReader($framework, [$framework, 'getSystemSetting']);
    $identities = new IdentityRepository($framework, $protector, $reader, $settings);
    $publicReader = new PrimaryLogReader($framework, static function($sql, $params) use ($framework) {
        checkSeal(!str_contains($sql, 'private_key_ciphertext'), 'Public CRL lookup selected private key material');
        return $framework->queryLogs($sql, $params);
    });
    $publicRoots = new PublicTrustRepository($publicReader, $settings);
    $crls = new CrlRepository($framework, $settings);
    $held = false;
    $lock = new PkiInitializationLock(static function($sql) use (&$held) {
        if (str_contains($sql, 'GET_LOCK')) { checkSeal(!$held, 'Nested CRL configuration lock'); $held = true; }
        else { checkSeal($held, 'CRL configuration lock was not held'); $held = false; }
        return new CrlTestResult([[1]]);
    });
    $snapshot = null;
    $transaction = static function($sql) use ($framework, &$snapshot) {
        if ($sql === 'START TRANSACTION') { $snapshot = [$framework->settings, $framework->logs]; }
        elseif ($sql === 'ROLLBACK') { [$framework->settings, $framework->logs] = $snapshot; }
        else { checkSeal($sql === 'COMMIT', 'Unexpected CRL transaction'); }
        return true;
    };
    $worker = new CrlPublicationService($framework, $publicRoots, $identities, $protector, $crls, $lock, $transaction);
    checkSeal($worker->run($now)['status'] === 'uninitialized', 'Uninitialized PKI failed cron');
    $id = $identities->append('root', $root); $identities->activate('root', $id);
    checkSeal($worker->run($now)['published'] === 1 && $crls->load($root->certificateDer)['number'] === 1, 'Initial CRL not published');
    checkSeal($worker->run($now + 1)['published'] === 0, 'Repeated cron unnecessarily published another CRL');
    $public = new PublicCrlService($publicRoots, $crls);
    $keyId = CrlIssuer::keyId($root->certificateDer);
    $response = $public->response($keyId, $now);
    checkSeal($response['status'] === 200 && $response['body'] === base64_decode($crls->load($root->certificateDer)['der_b64'])
        && $response['headers']['Content-Type'] === 'application/pkix-crl', 'Public response altered cached DER');
    checkSeal($public->response(str_repeat('0', 64), $now)['status'] === 404, 'Unknown CRL key was accepted');
    checkSeal($public->response($keyId, $now - 1)['status'] === 503, 'Future CRL was served');
    checkSeal($public->response($keyId, $now + CrlIssuer::LIFETIME)['status'] === 503, 'Expired CRL was served');
    // Simulate a future revocation write; cron must preserve its serials and counter.
    $crls->save($root->certificateDer, $revoked);
    $before = $framework->settings;
    $framework->failAudit = true;
    expectFailure(fn() => $worker->run($now + 86400), 'Audit failure did not fail publication');
    checkSeal($framework->settings === $before && !$held, 'Failed refresh lost the previous CRL or lock');
    $framework->failAudit = false;
    checkSeal($worker->run($now + 86400)['published'] === 1, 'Daily CRL was not refreshed');
    $updated = $crls->load($root->certificateDer);
    checkSeal($updated['number'] === 3 && $updated['entries'] === [$entry], 'Refresh lost counter or revoked serials');
    expectFailure(fn() => $worker->run($now), 'Backwards clock was accepted');

    $renewed = new GeneratedIdentity(reissueProbeRoot($root->certificateDer, $root->privateKey(), $now - 60, $now + 4000 * 86400), $root->privateKeyPem());
    $newId = $identities->append('root', $renewed); $identities->activate('root', $newId);
    checkSeal(CrlIssuer::url($surveyUrl, $renewed->certificateDer) === $url, 'Same-key renewal changed CRL URL');
    checkSeal($worker->run($now + 2 * 86400)['published'] === 1 && $crls->load($renewed->certificateDer)['number'] === 4
        && $crls->load($renewed->certificateDer)['entries'] === [$entry], 'Same-key renewal duplicated/reset CRL history');
    $public->response($keyId, $now + 2 * 86400);
    $key = CrlRepository::settingKey($keyId);
    $original = $framework->settings[$key];
    $framework->settings[$key] = '{broken';
    checkSeal($public->response($keyId, $now + 2 * 86400)['status'] === 503, 'Corrupt cache was exposed');
    expectFailure(fn() => $worker->run($now + 3 * 86400), 'Corrupt cache was replaced with an empty CRL');
    checkSeal($framework->settings[$key] === '{broken', 'Corrupt cache erased CRL history');
    $framework->settings[$key] = $original;
    $maximum = $builder->issue($renewed, PHP_INT_MAX, $now + 2 * 86400, [$entry]);
    $crls->save($renewed->certificateDer, $maximum);
    expectFailure(fn() => $worker->run($now + 3 * 86400), 'Exhausted CRL number wrapped');
    checkSeal(!$held, 'CRL error leaked its configuration lock');
    echo 'CRLs: OpenSSL signature/status checks, CDPs, cache rollback, daily refresh, same-key continuity and public failures passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    foreach ($paths as $path) { @unlink($path); }
}
