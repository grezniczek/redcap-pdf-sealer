<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Signer;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;

require __DIR__ . '/pdf_seal_bb.php';
require_once __DIR__ . '/support/pdf_timestamp_checks.php';

$tsa = $issuer->createTsa('PDF Seal Test', $root);
$internal = new InternalTimestampProvider(
    new InternalTsaService(TsaPolicy::DEFAULT_OID),
    new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$root->certificateDer]),
);
$provider = new class($internal) implements TimestampProvider {
    public string $request = '';
    public string $response = '';

    public function __construct(private readonly InternalTimestampProvider $internal) {}

    public function policyOid(): string
    {
        return $this->internal->policyOid();
    }

    public function respond(string $requestDer, int $now): string
    {
        $this->request = $requestDer;
        return $this->response = $this->internal->respond($requestDer, $now);
    }
};

foreach ($cases as $source) {
    $now = time();
    $result = $builder->sealTimestamped(
        $source, $project->certificateDer, $project->privateKey(), [$root->certificateDer], $now, $provider,
    );
    verifyTimestampedSeal($source, $result, $rootPem, $provider, $now);
}

$failingProvider = new class implements TimestampProvider {
    public function policyOid(): string
    {
        return TsaPolicy::DEFAULT_OID;
    }

    public function respond(string $requestDer, int $now): string
    {
        throw new RuntimeException('Simulated TSA failure');
    }
};
try {
    $builder->sealTimestamped($cases[0], $project->certificateDer, $project->privateKey(),
        [$root->certificateDer], time(), $failingProvider);
    throw new RuntimeException('B-T sealing silently succeeded after TSA failure');
} catch (RuntimeException $expected) {
    checkSeal($expected->getMessage() === 'Simulated TSA failure', 'Unexpected B-T failure: ' . $expected->getMessage());
}
$fallback = $builder->seal($cases[0], $project->certificateDer, $project->privateKey(), [$root->certificateDer], time());
$fallbackCms = verifySeal($cases[0], $fallback, $rootPem);
checkSeal((new Signer())->signatureTimestampTokens($fallbackCms) === [], 'B-B fallback was mislabeled with a timestamp');

echo 'PAdES B-T signature timestamps and independent RFC 3161 checks passed (', count($cases), " PDFs).\n";
