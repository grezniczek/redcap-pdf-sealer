<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Diagnostics\DiagnosticSnapshot;

require dirname(__DIR__) . '/autoload.php';

function check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
$framework = new class {
    public mixed $stored = null;
    public bool $fail = false;
    public function getSystemSetting(string $key): mixed { return $this->stored; }
    public function setSystemSetting(string $key, mixed $value): void
    {
        if ($this->fail) { throw new RuntimeException('Storage unavailable'); }
        $this->stored = $value;
    }
};
$store = new DiagnosticSnapshot($framework);
check($store->load() === null, 'Empty cache was not distinguished from a completed run');
$passed = ['passed' => true, 'checks' => array_fill_keys(DiagnosticSnapshot::CHECKS, 'passed'), 'versions' => null];
$store->save($passed + ['pdf' => 'discard test PDF', 'private_key' => 'discard private key'], 1700000000);
$reloaded = (new DiagnosticSnapshot($framework))->load();
check($reloaded === ['completed_at' => 1700000000] + $passed, 'Refresh lost diagnostic results');
check(!str_contains($framework->stored, 'discard'), 'Cached diagnostic leaked test material');
$failed = $passed;
$failed['passed'] = false;
$failed['checks']['root'] = 'failed';
$failed['checks']['signer'] = 'skipped';
$store->save($failed, 1700000010);
check($store->load() === ['completed_at' => 1700000010] + $failed, 'Failed run did not replace successful snapshot');
$previous = $framework->stored;
$framework->fail = true;
try { $store->save($passed, 1700000020); throw new LogicException('Write failure ignored'); }
catch (RuntimeException) {}
check($framework->stored === $previous, 'Write failure discarded prior result');
$framework->fail = false;
foreach (['not JSON', '[]', json_encode(['completed_at' => 1, 'passed' => true, 'checks' => []]),
    json_encode(['completed_at' => PHP_INT_MAX] + $passed),
    json_encode(['completed_at' => 1, 'passed' => true, 'checks' => $failed['checks']])] as $bad) {
    $framework->stored = $bad;
    try { $store->load(); throw new LogicException('Corrupt snapshot accepted'); }
    catch (RuntimeException | JsonException) {}
}
echo "Diagnostic snapshot: refresh persistence, failed results, minimal data, write failure, and malformed cache passed.\n";

$currentVersions = ['root' => str_repeat('a',32), 'tsa' => str_repeat('b',32),
    'tsa_issuer' => str_repeat('a',32), 'tsa_policy' => '2.25.123'];
$versioned = $passed; $versioned['versions'] = $currentVersions;
$stored = $store->save($versioned, 1700000030);
check(!DiagnosticSnapshot::versionsChanged($stored, $currentVersions), 'Matching version marked changed');
foreach (['root','tsa','tsa_issuer','tsa_policy'] as $field) {
    $changed = $currentVersions; $changed[$field] = $field === 'tsa_policy' ? '2.25.456' : str_repeat('c',32);
    check(DiagnosticSnapshot::versionsChanged($stored,$changed), 'Changed identity/policy not detected');
}
$legacy = $passed; unset($legacy['versions']);
$framework->stored = json_encode(['completed_at'=>1700000040] + $legacy);
check($store->load()['versions'] === null && DiagnosticSnapshot::versionsChanged($store->load(),$currentVersions),
    'Legacy snapshot silently claimed current version evidence');
$framework->stored = json_encode(['completed_at'=>1700000050] + $versioned + ['private_key'=>'discard']);
$loaded = $store->load();
check($loaded['completed_at'] === 1700000050 && $loaded['versions'] === $currentVersions && !isset($loaded['private_key']),
    'Version comparison changed original run time or retained secret fields');
foreach ([['root'=>'not-an-id'],['tsa_policy'=>'not-an-oid'],['tsa_policy'=>str_repeat('1',300)]] as $badVersions) {
    $badResult = $passed; $badResult['versions'] = $badVersions;
    try { $store->save($badResult,1700000060); throw new LogicException('Invalid versions accepted'); }
    catch (RuntimeException) {}
}
echo "Diagnostic versions: identity/issuer/policy changes, legacy evidence, original run time and public-only metadata passed.\n";
