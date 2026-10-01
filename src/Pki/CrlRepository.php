<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;

/** One atomic public snapshot per issuing key, including its counter and complete revocation list. */
final class CrlRepository
{
    public function __construct(
        private readonly object $framework,
        private readonly PrimarySystemSettingReader $settings,
    ) {}

    public function load(string $rootDer): ?array
    {
        $raw = $this->settings->get(self::settingKey(CrlIssuer::keyId($rootDer)));
        if ($raw === null) { return null; }
        if (!is_string($raw)) { throw new RuntimeException('Invalid CRL setting'); }
        $record = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($record)) { throw new RuntimeException('Invalid CRL setting'); }
        (new CrlIssuer())->verify($rootDer, $record);
        return $record;
    }

    /** Caller holds the configuration lock and a transaction spanning the audit and this write. */
    public function save(string $rootDer, array $record): void
    {
        (new CrlIssuer())->verify($rootDer, $record);
        $this->framework->setSystemSetting(self::settingKey(CrlIssuer::keyId($rootDer)),
            json_encode($record, JSON_THROW_ON_ERROR));
        // Read back from the primary, bypassing Framework's setting cache.
        if ($this->load($rootDer) !== $record) { throw new RuntimeException('CRL publication was not persisted'); }
    }

    public static function settingKey(string $keyId): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $keyId) !== 1) { throw new RuntimeException('Invalid CRL issuer key ID'); }
        return 'crl_' . $keyId;
    }
}
