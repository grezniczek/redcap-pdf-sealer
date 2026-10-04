<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanManager;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanRepository;
use Throwable;

/** Read-only projection of Core's project execution plan and workflow reservations. */
final class ProjectPipelineStatus
{
    /** @return array{state: string, positions: list<int>} */
    public static function inspect(int $pid, string $prefix): array
    {
        $status = ['state' => 'unavailable', 'positions' => []];
        try {
            if (!SealingSupport::inspect()['supported']
                || !defined(PdfExecutionPlanManager::class . '::CONTRACT_VERSION')
                || PdfExecutionPlanManager::CONTRACT_VERSION < 1
                || !class_exists(PdfExecutionPlanRepository::class)
                || !PdfExecutionPlanRepository::isProjectExecutionPlanStorageAvailable()) {
                return $status;
            }
            $configuration = PdfExecutionPlanManager::getProjectConfigurationState($pid);
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
                if (!$unresolved) {
                    $consentWorkflows = array_filter($configuration['workflows'] ?? [], static fn(array $workflow): bool =>
                        ($workflow['context']['document_type'] ?? null) === 'econsent');
                    $coversType = false;
                    $allReserved = $consentWorkflows !== [];
                    $anyReserved = false;
                    foreach ($consentWorkflows as $workflow) {
                        $reserved = ($workflow['terminal_action_reserved_for_core'] ?? false) === true;
                        $coversType = $coversType || ($workflow['covers_document_type'] ?? false) === true;
                        $allReserved = $allReserved && $reserved;
                        $anyReserved = $anyReserved || $reserved;
                    }
                    if ($allReserved && $coversType) {
                        $status['state'] = 'reserved_for_core';
                    } elseif ($anyReserved) {
                        $status['state'] = 'warning';
                    }
                }
            }
        } catch (Throwable) {
            $status = ['state' => 'unavailable', 'positions' => []];
        }
        return $status;
    }
}
