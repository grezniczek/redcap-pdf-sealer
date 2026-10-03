<?php

declare(strict_types=1);

namespace ExternalModules {
    if (!in_array($argv[1] ?? '', ['support-none', 'support-core'], true)) {
        final class PdfFinalize
        {
            public static bool $available = true;
            public static bool $fail = false;
            public static array $entries = [];
            public static int $configurationReads = 0;
            public static function isProjectExecutionPlanStorageAvailable(): bool { return self::$available; }
            public static function getProjectConfigurationState(int $pid): array
            {
                ++self::$configurationReads;
                if ($pid !== 461 || self::$fail) { throw new \RuntimeException('Unavailable'); }
                return ['execution_plan' => self::$entries];
            }
        }
    }
}

namespace Vanderbilt\REDCap\Classes\Settings {
    if (in_array($argv[1] ?? '', ['', 'support-core', 'support-both'], true)) {
        final class ProjectSettingKeys { public const EXTERNAL_MODULES_PDF_FINALIZE_EXECUTION_PLAN = 'pdf_finalize'; }
    } else {
        final class ProjectSettingKeys {} // An older Core has the class but lacks this new key.
    }
}

namespace {
    use ExternalModules\PdfFinalize;
    use DE\RUB\PDFSealerExternalModule\Pdf\ProjectPipelineStatus;

    require dirname(__DIR__) . '/autoload.php';
    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    if (str_starts_with($argv[1] ?? '', 'support-')) {
        $scenario = substr($argv[1], 8);
        $core = in_array($scenario, ['core', 'both'], true);
        $frameworkPresent = in_array($scenario, ['framework', 'both'], true);
        $sealingSupport = DE\RUB\PDFSealerExternalModule\Pdf\SealingSupport::inspect();
        check($sealingSupport === ['core' => $core, 'framework' => $frameworkPresent, 'supported' => $core && $frameworkPresent],
            'Incorrect installation support: ' . $scenario);
        $status = ProjectPipelineStatus::inspect(461, 'pdf_sealer');
        check($status === ['state' => $core && $frameworkPresent ? 'not_assigned' : 'unavailable', 'positions' => []],
            'Unsupported environment queried/reported a project assignment');
        if ($frameworkPresent) { check(PdfFinalize::$configurationReads === ($core ? 1 : 0), 'Unsupported Core read the pipeline'); }
        $strings = parse_ini_file(dirname(__DIR__) . '/lang/English.ini');
        $framework = new class($strings) {
            public function __construct(private array $strings) {}
            public function tt(string $key): string { return $this->strings[$key]; }
        };
        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start(); require dirname(__DIR__) . '/views/sealing-support.php'; $notice = ob_get_clean();
        if ($core && $frameworkPresent) { check(trim($notice) === '', 'Supported environment displayed a compatibility warning'); }
        else {
            $missing = []; if (!$core) { $missing[] = 'REDCap Core'; } if (!$frameworkPresent) { $missing[] = 'External Module Framework'; }
            check(str_contains($notice, 'Certificate management remains available.')
                && str_contains($notice, 'PDF finalization support is missing in: ' . implode(', ', $missing)),
                'Compatibility notice did not explain the missing component(s)');
        }
        echo 'Sealing support and shared notice: ' . $scenario . " passed.\n";
        exit;
    }
    foreach (['none', 'core', 'framework', 'both'] as $scenario) {
        $process = proc_open([PHP_BINARY, __FILE__, 'support-' . $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Could not start isolated support check');
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, $output);
        echo $output;
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
