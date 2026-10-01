<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Throwable;

/** Anonymous GET responses use committed public data only; unavailable/stale lists are never regenerated here. */
final class PublicCrlService
{
    public function __construct(
        private readonly PublicTrustRepository $roots,
        private readonly CrlRepository $crls,
    ) {}

    public function response(string $keyId, ?int $now = null): array
    {
        $now ??= time();
        $status = 404;
        if (preg_match('/^[0-9a-f]{64}$/D', $keyId) === 1) {
            try {
                foreach ($this->roots->roots() as $root) {
                    if (CrlIssuer::keyId($root['der']) !== $keyId) { continue; }
                    $status = 503;
                    $record = $this->crls->load($root['der']);
                    if ($record !== null && $record['this_update'] <= $now && $record['next_update'] > $now) {
                        return [
                            'status' => 200,
                            'headers' => [
                                'Content-Type' => 'application/pkix-crl',
                                'Content-Disposition' => 'inline; filename="pdf-sealer-' . $keyId . '.crl"',
                                'Cache-Control' => 'public, max-age=' . min(300, $record['next_update'] - $now),
                                'X-Content-Type-Options' => 'nosniff',
                            ],
                            'body' => base64_decode($record['der_b64'], true),
                        ];
                    }
                    break;
                }
            } catch (Throwable) {
                $status = 503;
            }
        }
        return ['status' => $status, 'headers' => [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'body' => "PDF Sealer CRL unavailable.\n"];
    }
}
