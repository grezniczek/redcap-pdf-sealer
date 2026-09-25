<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

/** Uses the instance TSA directly, without an HTTP request back to REDCap. */
final readonly class InternalTimestampProvider implements TimestampProvider
{
    public function __construct(
        private InternalTsaService $service,
        private TsaIdentity $identity,
    ) {}

    public function policyOid(): string
    {
        return $this->service->policyOid();
    }

    public function respond(string $requestDer, int $now): string
    {
        return $this->service->respond($requestDer, $this->identity, $now);
    }
}
