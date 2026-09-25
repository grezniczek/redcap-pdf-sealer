<?php

declare(strict_types=1);

namespace ExternalModules {
    class AbstractExternalModule
    {
        public object $framework;
    }
}

namespace {
    use DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule;

    require dirname(__DIR__) . '/PDFSealerExternalModule.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    final class FakeFramework
    {
        public bool $superuser = true;
        public ?int $projectId = null;
        public bool $failWrite = false;
        public array $settings = [];

        public function isSuperUser(): bool { return $this->superuser; }
        public function getProjectId(): ?int { return $this->projectId; }
        public function tt(string $key): string { return $key; }
        public function setSystemSetting(string $key, mixed $value): void
        {
            if ($this->failWrite) {
                throw new \RuntimeException('Storage unavailable');
            }
            $this->settings[$key] = $value;
        }
    }

    $framework = new FakeFramework();
    $module = new PDFSealerExternalModule();
    $module->framework = $framework;

    $result = $module->redcap_module_ajax('save_alert_recipients', "a@example.org; A@example.org\nb@example.org", null);
    check($result === ['ok' => true, 'recipients' => 'A@example.org, b@example.org'], 'Valid recipients were not saved');
    check($framework->settings['admin-alert-recipients'] === ['A@example.org', 'b@example.org'], 'Stored recipients differ');

    $result = $module->redcap_module_ajax('save_alert_recipients', 'bad address', null);
    check($result['ok'] === false && $framework->settings['admin-alert-recipients'] === ['A@example.org', 'b@example.org'],
        'Invalid addresses changed the setting');
    $result = $module->redcap_module_ajax('save_alert_recipients', '', null);
    check($result['ok'] === true && $framework->settings['admin-alert-recipients'] === [],
        'Empty input did not disable alarm email');

    $result = $module->redcap_module_ajax('download_root_certificate', 'unknown', null);
    check($result === ['ok' => false, 'message' => 'pki_invalid_request'],
        'Unsupported certificate format was accepted');

    foreach ([['superuser' => false, 'projectId' => null, 'context' => null],
              ['superuser' => true, 'projectId' => 461, 'context' => 461],
              ['superuser' => true, 'projectId' => null, 'context' => 461]] as $case) {
        $framework->superuser = $case['superuser'];
        $framework->projectId = $case['projectId'];
        try {
            $module->redcap_module_ajax('save_alert_recipients', 'new@example.org', $case['context']);
            throw new \RuntimeException('Unauthorized AJAX request was accepted');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'pki_access_denied', 'Unexpected unauthorized request outcome');
        }
        try {
            $module->redcap_module_ajax('download_root_certificate', 'pem', $case['context']);
            throw new \RuntimeException('Unauthorized download was accepted');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'pki_access_denied', 'Unexpected unauthorized download outcome');
        }
    }

    $framework->superuser = true;
    $framework->projectId = null;
    $framework->failWrite = true;
    $result = $module->redcap_module_ajax('save_alert_recipients', 'new@example.org', null);
    check($result === ['ok' => false, 'message' => 'admin_alert_recipients_save_failed'],
        'Storage failure was not reported');

    echo "PKI admin AJAX: recipients, download format, authorization, and storage failure passed.\n";
}
