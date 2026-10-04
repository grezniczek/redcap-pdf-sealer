<?php
// Render the real view with public metadata; no Framework/database or live PKI access.
declare(strict_types=1);
$labels = parse_ini_file(dirname(__DIR__) . '/lang/English.ini', false, INI_SCANNER_RAW);
$framework = new class($labels) {
    public function __construct(private array $labels) {}
    public function tt(string $key): string { return $this->labels[$key] ?? throw new RuntimeException('Missing label: ' . $key); }
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
    ['id' => 'builtin-ca', 'name' => 'Built-in CA', 'retired' => false, 'timestamp_source' => 'builtin-tsa', 'timestamp_alternatives' => ['external-tsa'], 'bb_fallback' => false],
    ['id' => 'external-a', 'name' => '<img src=x onerror=alert(1)>', 'retired' => true, 'timestamp_source' => 'external-tsa', 'timestamp_alternatives' => [], 'bb_fallback' => true],
    ['id' => 'external-b', 'name' => 'No timestamp CA', 'retired' => false, 'timestamp_source' => null, 'timestamp_alternatives' => [], 'bb_fallback' => false],
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
echo "Provider administration view checks passed\n";
