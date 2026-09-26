<?php

declare(strict_types=1);

namespace ExternalModules {
    final class PdfFinalize
    {
        public static bool $available = true;
        public static bool $fail = false;
        public static array $entries = [];
        public static function isProjectExecutionPlanStorageAvailable(): bool { return self::$available; }
        public static function getProjectConfigurationState(int $pid): array
        {
            if ($pid !== 461 || self::$fail) { throw new \RuntimeException('Unavailable'); }
            return ['execution_plan' => self::$entries];
        }
    }
}

namespace {
    use ExternalModules\PdfFinalize;
    use DE\RUB\PDFSealerExternalModule\Pdf\ProjectPipelineStatus;

    require dirname(__DIR__) . '/vendor/autoload.php';
    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    $inspect = static fn (): array => ProjectPipelineStatus::inspect(461, 'pdf_sealer');
    PdfFinalize::$available = false;
    check($inspect()['state'] === 'unavailable', 'Unsupported Core was not detected');
    PdfFinalize::$available = true;
    check($inspect()['state'] === 'not_assigned', 'Empty pipeline reported assigned');
    PdfFinalize::$entries = [[
        'identifier' => 'other:seal', 'position' => 1, 'resolved' => true, 'document_types' => ['econsent'],
    ]];
    check($inspect()['state'] === 'not_assigned', 'Another module was counted as this sealer');
    PdfFinalize::$entries[] = [
        'identifier' => 'pdf_sealer:seal', 'position' => 2, 'resolved' => true,
        'document_types' => ['econsent'], 'warnings' => [],
    ];
    check($inspect() === ['state' => 'assigned', 'positions' => [2]], 'Assigned operation not recognized');
    PdfFinalize::$entries[1]['warnings'] = [['code' => 'potentially_unreachable']];
    check($inspect()['state'] === 'warning', 'Earlier terminal operation warning was hidden');
    PdfFinalize::$entries[1]['resolved'] = false;
    check($inspect()['state'] === 'unresolved', 'Unavailable assigned operation reported runnable');
    PdfFinalize::$entries[1]['resolved'] = true;
    PdfFinalize::$entries[1]['document_types'] = ['record_pdf'];
    check($inspect()['state'] === 'unresolved', 'Non-eConsent operation reported runnable');
    PdfFinalize::$fail = true;
    check($inspect() === ['state' => 'unavailable', 'positions' => []], 'Read failure looked unassigned');
    echo "Project pipeline status: unavailable, unassigned, assigned, unresolved, and warning states passed.\n";
}
