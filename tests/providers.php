<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository;

require dirname(__DIR__) . '/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects(callable $work): void
{
    try { $work(); } catch (Throwable) { return; }
    throw new RuntimeException('Invalid configuration was accepted');
}
$framework = new class {
    public array $settings = [];
    public function setSystemSetting(string $key, string $value): void { $this->settings[$key] = $value; }
};
$reader = new PrimarySystemSettingReader($framework, fn(string $key) => $framework->settings[$key] ?? null);
$repository = new ProviderRepository($framework, $reader);
rejects(fn() => $repository->defaultId());
check($framework->settings === [], 'Read initialized storage');
$repository->initialize(str_repeat('a', 32), str_repeat('b', 32));
$baseline = $framework->settings;
rejects(fn() => $repository->initialize(str_repeat('c', 32), str_repeat('d', 32)));
check($framework->settings === $baseline, 'Repeat initialization overwrote configuration');
check($repository->defaultId() === 'builtin-ca', 'Default provider missing');
check($repository->source('builtin-tsa')['issuer_identity_id'] === str_repeat('a', 32), 'Wrong timestamp issuer');
foreach (['none', 'internal'] as $mode) {
    foreach ([false, true] as $fallback) {
        $repository->saveBuiltinTimestamp($mode, $fallback);
        $settings = $repository->timestampSettings('builtin-ca');
        check($settings->mode === $mode && $settings->fallback === $fallback, 'Policy round trip failed');
        check($repository->provider('builtin-ca')['issuer_identity_id'] === str_repeat('a', 32), 'Policy update changed issuer');
    }
}
rejects(fn() => $repository->provider('../builtin-ca'));
rejects(fn() => $repository->source('missing'));
rejects(fn() => $repository->saveBuiltinTimestamp('external', false));
$framework->settings = $baseline;
$provider = $repository->provider('builtin-ca');
foreach ([null, 'bad-json', '[]', json_encode($provider + ['unexpected' => true]),
    json_encode(array_replace($provider, ['bb_fallback' => 'false'])),
    json_encode(array_replace($provider, ['kind' => 'external'])),
    json_encode(array_replace($provider, ['id' => 'different'])),
    json_encode(array_replace($provider, ['issuer_identity_id' => 'invalid']))] as $invalid) {
    $framework->settings['ca_provider_builtin-ca'] = $invalid;
    rejects(fn() => $repository->provider('builtin-ca'));
}
// JSON object field order is immaterial.
$framework->settings['ca_provider_builtin-ca'] = json_encode(array_reverse($provider, true));
check($repository->provider('builtin-ca')['id'] === 'builtin-ca', 'Object ordering affected validation');
$framework->settings = $baseline;
unset($framework->settings['tsa_source_builtin-tsa']);
rejects(fn() => $repository->timestampSettings('builtin-ca'));
// Explicit no-timestamp policy does not need a timestamp source.
$provider['timestamp_source'] = null;
$framework->settings['ca_provider_builtin-ca'] = json_encode($provider);
check($repository->timestampSettings('builtin-ca')->mode === 'none', 'B-B depends on timestamp configuration');
echo "Providers: initialization, explicit policy, primary-reader storage, and malformed/unsupported configuration rejection passed.\n";
