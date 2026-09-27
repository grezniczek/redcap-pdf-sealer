<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

/** Supplies DER RFC 3161 responses to Tecnick's request and validation codec. */
interface TimestampProvider
{
    /** Expected policy, or empty for a trusted external TSA's default policy. */
    public function policyOid(): string;

    public function respond(string $requestDer, int $now): string;
}
