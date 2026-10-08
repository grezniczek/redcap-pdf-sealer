<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require_once (getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase') . '/Classes/PdfFinalization/PdfFinalizeResult.php';
foreach (glob((getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase') . '/Classes/PdfFinalization/*.php') as $file) {
    require_once $file;
}

use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfFinalizationPolicy;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfFinalizationRunner;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfOperationProvider;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfTestTerminalAction;

$framework = new class {
    public function __call(string $name, array $arguments): never
    {
        throw new RuntimeException('Reserved sealing touched Framework service: ' . $name);
    }
};
$path = tempnam(sys_get_temp_dir(), 'pdf-sealer-reservation-');
try {
    $source = "%PDF-1.4\nunchanged under Core reservation\n%%EOF";
    file_put_contents($path, $source);
    $service = new PdfFinalizeService($framework);
    $result = $service->finalize($path, ['id' => 'seal'], [
        'document_type' => 'econsent', 'project_id' => 123, 'terminal_action_reserved_for_core' => true,
    ]);
    if (!$result->isUnchanged() || $result->isTerminal() || file_get_contents($path) !== $source) {
        throw new RuntimeException('Reserved sealing did not preserve the nonterminal input.');
    }
    foreach ([[], ['terminal_action_reserved_for_core' => 'true'], ['terminal_action_reserved_for_core' => 1]] as $reservation) {
        $result = $service->finalize($path, ['id' => 'seal'], $reservation + ['document_type' => 'econsent']);
        if (!$result->isFailed() || $result->getErrorCode() !== 'INVALID_CONTEXT' || file_get_contents($path) !== $source) {
            throw new RuntimeException('Malformed reservation context was accepted.');
        }
    }
    $provider = new class($service) implements PdfOperationProvider {
        public int $invocations = 0;
        public function __construct(private PdfFinalizeService $service) {}
        public function getOperations(int|string $projectId): array
        {
            return [['identifier' => 'pdf_sealer:seal', 'module_prefix' => 'pdf_sealer',
                'module_version' => 'v9.9.9', 'module_name' => 'PDF Sealer',
                'operation' => ['id' => 'seal', 'document_types' => ['econsent'], 'terminal' => true]]];
        }
        public function invoke(string $workingPdfPath, array $resolvedOperation, array $context): mixed
        {
            ++$this->invocations;
            if (($context['terminal_action_reserved_for_core'] ?? null) !== true) {
                throw new RuntimeException('Core did not reserve terminal finalization.');
            }
            return $this->service->finalize($workingPdfPath, $resolvedOperation['operation'], $context);
        }
    };
    $events = [];
    $runner = new PdfFinalizationRunner($provider, static function (array $event) use (&$events): void { $events[] = $event; });
    $outcome = $runner->run($path, ['pdf_sealer:seal'], 123, ['document_type' => 'econsent'],
        new PdfFinalizationPolicy(new PdfTestTerminalAction(), true));
    $completedOperations = array_values(array_filter($events, static fn(array $event): bool => $event['event'] === 'operation_completed'));
    if ($provider->invocations !== 1 || $outcome->getFailures() !== [] || !$outcome->canCommit()
        || $outcome->getTerminalActionStatus() !== 'succeeded' || $outcome->getTerminalActionIdentifier() !== 'core:test_terminal'
        || $outcome->getPdfPath() !== $path || file_get_contents($path) !== $source
        || array_column($completedOperations, 'operation_identifier') !== ['pdf_sealer:seal', 'core:test_terminal']
        || array_column($completedOperations, 'terminal') !== [false, true]
        || ($completedOperations[1]['metadata']['cryptographic_seal_applied'] ?? null) !== false) {
        throw new RuntimeException('Sealer-to-Core test finalization handoff failed.');
    }
    echo "Core terminal reservation: skipped without Framework/PKI/logging side effects; malformed context rejected.\n";
    echo "Core runner: real Sealer yields unchanged, Core test action runs last, original bytes preserved without signing.\n";
} finally {
    unlink($path);
}
