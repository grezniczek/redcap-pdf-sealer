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
$assignableProviders = array_values(array_filter($providerCatalog, static fn(array $p): bool => !$p['retired']));
$assignmentProjects = $transitionProjects = $renewalProjects = $revocationProjects = [['project_id' => 524, 'app_title' => '<Project & title>', 'status' => 0, 'completed_time' => null]];
$assignmentProjects = array_merge($assignmentProjects, [
    ['project_id' => 525, 'app_title' => 'Production project', 'status' => 1, 'completed_time' => null],
    ['project_id' => 526, 'app_title' => 'Analysis project', 'status' => 2, 'completed_time' => null],
    ['project_id' => 527, 'app_title' => 'Completed project', 'status' => 2, 'completed_time' => '2026-10-04 12:00:00'],
]);
$transitionProjects[0]['provider'] = ['provider_id' => 'builtin-ca', 'identity_id' => 'signer', 'pending_provider_id' => 'external-a', 'transition_id' => 'transition', 'enrollment_id' => 'csr'];
$transitionProjects[] = ['project_id' => 528, 'app_title' => 'Awaiting CSR', 'status' => 1, 'completed_time' => null,
    'provider' => ['provider_id' => 'external-b', 'identity_id' => null, 'pending_provider_id' => null, 'transition_id' => null, 'enrollment_id' => 'csr']];
$providersUnavailable = false;
ob_start(); require dirname(__DIR__) . '/views/provider-workflows.php'; $workflows = ob_get_clean();
check(substr_count($workflows, 'data-provider-workflow="') === 5, 'Missing workflow launchers');
check(substr_count($workflows, 'data-provider-workflow-host="') === 5, 'Missing hidden workflow hosts');
check(substr_count($workflows, 'pdf-sealer-dialog-body') === 5, 'Missing shared workflow body style');
check(substr_count($workflows, '<form ') === 5, 'Missing workflow forms');
$section = explode('<div data-provider-workflow-host=', $workflows, 2)[0];
check(str_contains($section, 'Administrative workflows') && !str_contains($section, '<form '), 'Forms still expand the visible page');
check(!str_contains($workflows, '<Project') && str_contains($workflows, '&lt;Project &amp; title&gt;'), 'Project labels must stay escaped');
check(substr_count($workflows, 'data-workflow-project') === 2, 'Missing remaining dialog project pickers');
check(substr_count($workflows, 'data-assignment-pid=') === 4 && !str_contains($workflows, 'id="provider-pid"'), 'Assignment must use a project table');
foreach (['Development', 'Production', 'Analysis/Cleanup', 'Completed'] as $status) {
    check(str_contains($workflows, '<td data-assignment-status>' . $status . '</td>'), 'Missing project status ' . $status);
}
check(str_contains($workflows, 'aria-label="Select project 524"'), 'Missing accessible project checkbox');
check(str_contains($workflows, 'Assign CA provider to the selected projects'), 'Missing bulk assignment action');
check(str_contains($workflows, 'id="pdf-sealer-transition-projects"') && !str_contains($workflows, 'id="transition-pid"'), 'Transition must use a project table');
check(str_contains($workflows, '<div>Built-in CA</div>') && str_contains($workflows, 'Pending provider: &lt;img'), 'Missing escaped current/pending provider details');
check(str_contains($workflows, 'An active signing identity is assigned.') && str_contains($workflows, 'No active signing identity is assigned.'), 'Missing signing assignment details');
check(str_contains($workflows, 'Cancel it on the project status page') && str_contains($workflows, 'Cancel pending provider changes for the selected projects'), 'Missing CSR guard/cancellation action');
check(preg_match_all('/\bid="([^"]+)"/', $workflows, $ids) !== false && count($ids[1]) === count(array_unique($ids[1])), 'Duplicate workflow IDs');
echo "Provider administration and workflow view checks passed\n";
