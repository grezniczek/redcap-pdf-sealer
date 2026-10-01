<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use Closure;

/** Uses the instance TSA directly, without an HTTP request back to REDCap. */
final readonly class InternalTimestampProvider implements TimestampProvider
{
    private ?Closure $clock;

    public function __construct(
        private InternalTsaService $service,
        private TsaIdentity $identity,
        ?callable $clock = null,
    ) {
        // Production supplies the server clock; isolated crypto tests may supply an explicit time.
        $this->clock = $clock === null ? null : Closure::fromCallable($clock);
    }

    public function policyOid(): string
    {
        return $this->service->policyOid();
    }

    public function respond(string $requestDer, int $now): string
    {
        return $this->service->respond($requestDer, $this->identity, $this->clock === null ? $now : ($this->clock)());
    }
}
