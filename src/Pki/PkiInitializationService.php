<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Closure;
use RuntimeException;
use Throwable;

/** Explicit one-time root and TSA creation; never repairs or replaces a broken PKI. */
final class PkiInitializationService
{
    private Closure $transactionQuery;

    /** @param null|callable(string):mixed $transactionQuery Test override for transaction commands. */
    public function __construct(
        private readonly object $framework,
        private readonly IdentityRepository $identities,
        private readonly ProjectBindingRepository $bindings,
        private readonly CertificateIssuer $issuer,
        private readonly PkiHealthService $health,
        private readonly PkiInitializationLock $lock,
        ?callable $transactionQuery = null,
    ) {
        $this->transactionQuery = $transactionQuery === null
            ? fn (string $sql): mixed => $this->framework->query($sql, [])
            : Closure::fromCallable($transactionQuery);
    }

    public function initialize(string $organization): void
    {
        $this->lock->withLock(function () use ($organization): void {
            if ($this->health->inspect(time())->status !== PkiHealth::Uninitialized
                || $this->identities->hasRole('tsa')
                || $this->identities->hasRole('project')
                || $this->identities->activeId('tsa') !== null
                || $this->bindings->hasAny()) {
                throw new RuntimeException('Existing PKI material prevents initialization');
            }

            // Generate before opening the transaction; these objects remain memory-only until all checks pass.
            $root = $this->issuer->createRoot($organization);
            $tsa = $this->issuer->createTsa($organization, $root);
            $this->transaction('START TRANSACTION');
            try {
                $this->framework->setSystemSetting('organization', $organization);
                $rootId = $this->identities->append('root', $root);
                $this->identities->activate('root', $rootId);
                $tsaId = $this->identities->append('tsa', $tsa);
                $this->identities->activate('tsa', $tsaId);
                if ($this->health->inspect(time())->status !== PkiHealth::Ready) {
                    throw new RuntimeException('Initialized PKI did not pass health checks');
                }
                $this->transaction('COMMIT');
            } catch (Throwable $e) {
                $this->transaction('ROLLBACK');
                throw $e;
            }
        });
    }

    private function transaction(string $sql): void
    {
        if (($this->transactionQuery)($sql) === false) {
            throw new RuntimeException('PKI initialization transaction failed');
        }
    }
}
