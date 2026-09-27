<?php

declare(strict_types=1);

// Exercise the real finalizer/result contract with fake database and project logging.
require dirname(__DIR__) . '/autoload.php';
require (getenv('PDF_SEALER_FRAMEWORK_ROOT') ?: '/home/gr/redcap/external_modules') . '/classes/PdfFinalizeResult.php';
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
final class Result {
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_row(): ?array { return array_shift($this->rows); }
}
$held = [];
function db_query(string $sql, array $params, mixed ...$rest): Result {
    global $held;
    check(($rest[2] ?? null) === true, 'Gate did not use primary connection');
    if (str_contains($sql, 'GET_LOCK')) {
        check(!isset($held[$params[0]]), 'Unexpected nested lock');
        $held[$params[0]] = true;
        return new Result([[1]]);
    }
    if (str_contains($sql, 'RELEASE_LOCK')) {
        check(isset($held[$params[0]]), 'Unexpected lock release');
        unset($held[$params[0]]);
        return new Result([[1]]);
    }
    if (($params[0] ?? null) === 'project_identity_binding') {
        check(isset($held['pdf_sealer_issue_101']), 'Binding read without project lock');
        return new Result([]);
    }
    check(($params[1] ?? null) === 'require_ca_assignment' && isset($held['pdf_sealer_initialize']), 'Read beyond gate or without configuration lock');
    return new Result([['value'=>'true','type'=>'string']]);
}
final class REDCap {
    public static array $events = [];
    public static function logEvent(...$args): void { self::$events[] = $args; }
}
$f = new class {
    public array $logs = [];
    public function getProjectId(): int { return 101; }
    public function getModuleInstance(): object { return (object)['PREFIX'=>'pdf_sealer']; }
    public function prefixSettingKey(string $key): string { return $key; }
    public function getQueryLogsSql(string $sql): string { return $sql; }
    public function createTempFile(): string { throw new RuntimeException('Blocked finalizer tried issuance'); }
    public function log(string $message, array $values): int {
        check($message === 'seal_event', 'Gate wrote certificate/binding/serial/alarm material');
        $this->logs[] = $values;
        return count($this->logs);
    }
};
$path = tempnam('/tmp', 'pdf-sealer-gate-');
try {
    $source = DE\RUB\PDFSealerExternalModule\Diagnostics\PkiDiagnosticService::samplePdf();
    file_put_contents($path,$source);
    $result = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],[
        'document_type'=>'econsent','project_id'=>101,'record_id'=>'18','event_id'=>1592,'generation_id'=>'test-gate',
    ]);
    check($result->isFailed() && !$result->isTerminal() && $result->getErrorCode() === 'CA_ASSIGNMENT_REQUIRED', 'Wrong result contract');
    check(file_get_contents($path) === $source, 'Blocked sealing changed PDF bytes');
    check(count($f->logs) === 1 && $f->logs[0]['error_code'] === 'CA_ASSIGNMENT_REQUIRED', 'Failure diagnostics missing');
    check(REDCap::$events === [['PDF seal failed: CA assignment required','Reference: test-gate','','18',1592,101]], 'Project log lost reason or record/event context');
    check($held === [], 'Finalizer retained advisory locks');
    echo "Assignment gate finalizer: explicit failure, unchanged PDF, minimal project log with record/event, no PKI writes or alarm, and released locks passed.\n";
} finally { unlink($path); }
