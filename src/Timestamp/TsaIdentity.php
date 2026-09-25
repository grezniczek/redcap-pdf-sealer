<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use OpenSSLAsymmetricKey;

/** A loaded, dedicated TSA identity. Persistent key protection belongs to the PKI layer. */
final readonly class TsaIdentity
{
    /** @param list<string> $chainDer DER-encoded issuer certificates. */
    public function __construct(
        public string $certificateDer,
        public OpenSSLAsymmetricKey $privateKey,
        public array $chainDer = [],
    ) {}
}
