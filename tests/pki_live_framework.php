<?php

declare(strict_types=1);

// Run only against a disposable REDCap development instance. All log writes roll back.
if (getenv('PDF_SEALER_LIVE_TEST') !== '1') {
    throw new RuntimeException('Set PDF_SEALER_LIVE_TEST=1 to run this development-instance check');
}

$_SERVER['PHP_SELF'] = 'pdf_sealer_live_framework.php';
require '/home/gr/redcap/codebase/Config/init_global.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

$framework = \ExternalModules\ExternalModules::getFrameworkInstance('pdf_sealer', 'v9.9.9');
$protector = new SecretProtector();
$repository = new IdentityRepository($framework, $protector);
$issuer = new CertificateIssuer([$framework, 'createTempFile']);
$root = $issuer->createRoot('PDF Sealer Integration Test');

$probe = 'pdf-sealer-crypto-probe-' . bin2hex(random_bytes(8));
if ($protector->decrypt($protector->encrypt($probe)) !== $probe) {
    throw new RuntimeException('REDCap encryption round trip failed');
}

if (db_query('START TRANSACTION') === false) {
    throw new RuntimeException('Could not start test transaction');
}
try {
    $id = $repository->append('root', $root);
    $stored = $repository->find($id);
    if (!$repository->hasRole('root')) {
        throw new RuntimeException('System-scoped role lookup missed the test identity');
    }
    if ($stored === null || $stored->role !== 'root'
        || $stored->certificateDer !== $root->certificateDer
        || !openssl_x509_check_private_key(
            \Com\Tecnick\Pdf\Sign\Cms\Certificate::derToPem($stored->certificateDer),
            $stored->privateKey($protector),
        )) {
        throw new RuntimeException('Stored identity did not round trip through the Framework');
    }
    $row = $framework->queryLogs(
        'SELECT project_id, record, private_key_ciphertext WHERE message = ? AND identity_id = ? LIMIT 1',
        ['pki_identity', $id],
    )->fetch_assoc();
    if ($row === null || $row['project_id'] !== null || $row['record'] !== null
        || !str_starts_with($row['private_key_ciphertext'] ?? '', 'redcap-v1:')
        || str_contains($row['private_key_ciphertext'], 'PRIVATE KEY')) {
        throw new RuntimeException('Identity log scope or encryption is incorrect');
    }
} finally {
    if (db_query('ROLLBACK') === false) {
        throw new RuntimeException('Could not roll back test records');
    }
}

if ($repository->find($id) !== null) {
    throw new RuntimeException('Test identity remained after rollback');
}
echo "Live Framework PKI storage, real REDCap crypto, system scope, and rollback passed.\n";
