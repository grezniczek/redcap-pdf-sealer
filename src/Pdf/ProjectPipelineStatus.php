<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use ExternalModules\PdfFinalize;
use Throwable;

/** Read-only projection of the Framework's project execution plan. */
final class ProjectPipelineStatus
{
    /** @return array{state: string, positions: list<int>} */
    public static function inspect(int $pid, string $prefix): array
    {
        $status = ['state' => 'unavailable', 'positions' => []];
        try {
            if (!class_exists(PdfFinalize::class)
                || !method_exists(PdfFinalize::class, 'isProjectExecutionPlanStorageAvailable')
                || !method_exists(PdfFinalize::class, 'getProjectConfigurationState')
                || !PdfFinalize::isProjectExecutionPlanStorageAvailable()) {
                return $status;
            }
            $configuration = PdfFinalize::getProjectConfigurationState($pid);
            $status['state'] = 'not_assigned';
            $warning = false;
            $unresolved = false;
            foreach ($configuration['execution_plan'] as $entry) {
                if ($entry['identifier'] !== $prefix . ':seal') {
                    continue;
                }
                $status['positions'][] = (int) $entry['position'];
                $unresolved = $unresolved || !$entry['resolved']
                    || !array_intersect(['econsent', '*'], $entry['document_types'] ?? []);
                $warning = $warning || !empty($entry['warnings']);
            }
            if ($status['positions'] !== []) {
                $status['state'] = $unresolved ? 'unresolved' : ($warning ? 'warning' : 'assigned');
            }
        } catch (Throwable) {
            $status = ['state' => 'unavailable', 'positions' => []];
        }
        return $status;
    }
}
