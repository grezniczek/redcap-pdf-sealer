<?php
// Render the real view with public metadata; no Framework/database or live PKI access.
declare(strict_types=1);
$labels = parse_ini_file(dirname(__DIR__) . '/lang/English.ini', false, INI_SCANNER_RAW);
$framework = new class($labels) {
    public function __construct(private array $labels) {}
    public function tt(string $key, mixed ...$values): string {
        $text = $this->labels[$key] ?? throw new RuntimeException('Missing label: ' . $key);
        foreach ($values as $index => $value) { $text = str_replace('{' . $index . '}', htmlentities((string) $value, ENT_QUOTES, 'UTF-8'), $text); }
        return $text;
    }
};
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$module = new class {
    public static function certificateSubjectHtml(string $subject): string { return htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); }
};
$assignmentRequired = true; $assignmentProjectsUnavailable = false;
$sourceChoices = ['none' => 'No timestamp (PAdES B-B)', 'builtin-tsa' => 'Internal TSA', 'external-tsa' => 'Remote <TSA>'];
$certificates = ['root' => ['details' => ['validTo_time_t' => 2000000000]]];
$renderCertificate = static function(string $role): void { echo '<dl data-test-root>Root certificate</dl>'; };
$providerCatalog = [
    ['kind' => 'internal', 'id' => 'builtin-ca', 'name' => 'Built-in CA', 'retired' => false, 'timestamp_source' => 'builtin-tsa', 'timestamp_alternatives' => ['external-tsa'], 'bb_fallback' => false],
    ['kind' => 'external', 'id' => 'external-a', 'name' => '<img src=x onerror=alert(1)>', 'retired' => true, 'timestamp_source' => 'external-tsa', 'timestamp_alternatives' => [], 'bb_fallback' => true],
    ['kind' => 'external', 'id' => 'external-b', 'name' => 'No timestamp CA', 'retired' => false, 'timestamp_source' => null, 'timestamp_alternatives' => [], 'bb_fallback' => false],
];
$providerCertificates = [];
foreach ([['external-a', 2100000000], ['external-a', 1900000000], ['external-b', 2200000000]] as [$id, $until]) {
    $providerCertificates[] = ['provider_id' => $id, 'trust_anchor' => true, 'subject' => '/O=Example/CN=CA',
        'fingerprint' => 'sha256', 'thumbprint' => 'sha1', 'valid_until' => $until];
}
ob_start(); require dirname(__DIR__) . '/views/providers.php'; $html = ob_get_clean();
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['builtin-ca' => 2000000000, 'external-a' => 1900000000, 'external-b' => 2200000000] as $id => $until) {
    check(preg_match('/<tr data-provider-id="' . $id . '".*?<\/tr>/s', $html, $match) === 1, 'Missing catalog row');
    check(str_contains($match[0], 'data-order="' . $until . '"'), 'Earliest chain expiry not selected for ' . $id);
    check(substr_count($match[0], '<button ') === 1 && str_contains($match[0], '>Manage</button>'), 'Unexpected catalog actions');
}
check(str_contains($html, '<td>Internal</td>') && str_contains($html, '<td>None</td>'), 'Wrong concise timestamp modes');
check(str_contains($html, 'Remote &lt;TSA&gt;') && !str_contains($html, '<img'), 'Dynamic names must be escaped');
check(substr_count($html, '<template ') === 3 && str_contains($html, 'data-test-root'), 'Missing certificate detail templates');
check(!str_contains($html, 'id="assignment-required"') && str_contains($html, 'Change assignment policy'), 'Policy editing still inline');
check(substr_count($html, 'pdf-sealer-dialog-body') === 3, 'Missing shared dialog body style');

$projectsUnavailable = false;
$projectOverview = [['pid' => 524, 'name' => '<Project & title>', 'status' => 'development', 'enabled' => true, 'deleted' => false,
    'binding' => null, 'certificate' => null, 'unavailable' => false, 'eligible' => ['assign' => true]]];
ob_start(); require dirname(__DIR__) . '/views/provider-workflows.php'; $workflows = ob_get_clean();
check(substr_count($workflows, 'data-provider-workflow="') === 1 && substr_count($workflows, '<form ') === 1, 'Only registration should remain on CA providers');
check(str_contains($workflows, 'Administrative workflows') && !str_contains($workflows, 'data-workflow-project'), 'Repeated project pickers remain');
ob_start(); require dirname(__DIR__) . '/views/projects-admin.php'; $projects = ob_get_clean();
check(substr_count($projects, '<table ') === 1 && substr_count($projects, 'data-project-action="') === 5, 'Shared overview/actions missing');
check(!str_contains($projects, '<form ') && !str_contains($projects, '<Project') && !str_contains($projects, '<img'), 'Forms should live in dialogs; metadata must be escaped');
check(str_contains($projects, 'External enrollment') && str_contains($projects, 'Pending CSR'), 'Missing presets');
check(preg_match('/data-projects="([^"]+)"/', $projects, $match) === 1, 'Missing public overview payload');
check(json_decode(htmlspecialchars_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR)[0]['name'] === '<Project & title>', 'Public payload must round-trip safely');
check(preg_match('/data-providers="([^"]+)"/', $projects, $match) === 1, 'Missing provider catalog');
check(json_decode(htmlspecialchars_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR)[0]['name'] === 'Built-in CA', 'Built-in name missing');
echo "Provider and shared project administration view checks passed\n";
