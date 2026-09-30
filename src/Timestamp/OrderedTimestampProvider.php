<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use Closure;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\SignedDataVerifier;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client, Config, Request};
use RuntimeException;
use Throwable;

/** Explicit source order for one signature imprint, with a shared monotonic deadline. */
final class OrderedTimestampProvider implements TimestampProvider
{
    public const MAX_ALTERNATIVES = 2;
    public const BUDGET_SECONDS = 20;
    private Closure $factory;
    private Closure $clock;
    private array $attempted = [];
    private ?string $selected = null;

    /** @param list<string> $sourceIds @param callable(string,float):TimestampProvider $factory */
    public function __construct(private readonly array $sourceIds, callable $factory, ?callable $clock = null)
    {
        if (!array_is_list($sourceIds) || count($sourceIds) < 1 || count($sourceIds) > 1 + self::MAX_ALTERNATIVES
            || count(array_unique($sourceIds, SORT_REGULAR)) !== count($sourceIds)) {
            throw new RuntimeException('Invalid timestamp source order');
        }
        foreach ($sourceIds as $id) { \DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository::assertId($id); }
        $this->factory = Closure::fromCallable($factory);
        $this->clock = $clock === null ? static fn(): float => hrtime(true) / 1e9 : Closure::fromCallable($clock);
    }

    // No common reqPolicy: each selected provider enforces its own configured policy.
    public function policyOid(): string { return ''; }
    public function attemptedSources(): array { return $this->attempted; }
    public function selectedSource(): ?string { return $this->selected; }

    public function respond(string $requestDer, int $now): string
    {
        if ($this->attempted !== []) { throw new RuntimeException('Timestamp order cannot be reused'); }
        $deadline = ($this->clock)() + self::BUDGET_SECONDS;
        foreach ($this->sourceIds as $id) {
            if (($this->clock)() >= $deadline) { break; }
            $this->attempted[] = $id;
            try {
                $provider = ($this->factory)($id, $deadline);
                if (($this->clock)() >= $deadline) { break; }
                $response = $provider->respond($requestDer, $now);
                if (strlen($response) > HttpsTimestampTransport::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Timestamp response exceeds limit');
                }
                // Validate before choosing a source, so a malformed/unacceptable
                // response proceeds to the next source rather than escaping to B-B.
                $asn1 = new PolicyOidAsn1($provider->policyOid());
                $verifier = new SignedDataVerifier($asn1, requireSigningCertificate: true,
                    allowLegacyEssSha1: $provider instanceof ExternalTimestampProvider);
                $client = new Client(new Config('https://timestamp.invalid/'), $asn1, verifier: $verifier);
                $client->parseResponse($response, $this->request($requestDer, $asn1, $provider->policyOid()), $now);
                if (($this->clock)() >= $deadline) { break; }
                $this->selected = $id;
                return $response;
            } catch (Throwable) {
                // Only source IDs are exposed; do not retain remote exceptions or credentials.
            }
        }
        throw new RuntimeException('No configured timestamp source succeeded within the budget');
    }

    /** The builder supplies a nonce-bearing SHA-256 request without reqPolicy. */
    private function request(string $der, PolicyOidAsn1 $asn1, string $policy): Request
    {
        $body = $asn1->readSingleElement($der, 0x30, 'TimeStampReq')['value'];
        $offset = 0;
        $version = $asn1->readTlv($body, $offset);
        $imprint = $asn1->readTlv($body, $offset);
        $nonce = $asn1->readTlv($body, $offset);
        $certReq = $asn1->readTlv($body, $offset);
        $inner = 0;
        $algorithm = $asn1->readTlv($imprint['value'], $inner);
        $hash = $asn1->readTlv($imprint['value'], $inner);
        $oid = $asn1->decodeAlgorithmIdentifier($algorithm['raw'], 'timestamp imprint');
        if ($version['raw'] !== $asn1->encodeInteger(1) || $imprint['tag'] !== 0x30
            || $oid !== '2.16.840.1.101.3.4.2.1' || $hash['tag'] !== 0x04 || strlen($hash['value']) !== 32
            || $inner !== strlen($imprint['value']) || $nonce['tag'] !== 0x02
            || $certReq['raw'] !== $asn1->encodeBoolean(true) || $offset !== strlen($body)) {
            throw new RuntimeException('Unsupported ordered timestamp request');
        }
        return new Request($der, $hash['value'], $oid, $nonce['raw'], $policy);
    }
}
