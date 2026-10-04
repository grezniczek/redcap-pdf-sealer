<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require_once (getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase') . '/Classes/PdfFinalization/PdfFinalizeResult.php';

use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;

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
    echo "Core terminal reservation: skipped without Framework/PKI/logging side effects; malformed context rejected.\n";
} finally {
    unlink($path);
}
