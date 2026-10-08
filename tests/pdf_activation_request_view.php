<?php

declare(strict_types=1);

namespace ExternalModules {
    final class ExternalModules
    {
        public static string $fixtureRoot;
        public static array $configVersions = [];
        public static function isSuperUser(): bool { return true; }
        public static function getProjectId(): string { return '533'; }
        public static function getPrefix(): string { return 'pdf_sealer'; }
        public static function getEnabledVersion(string $prefix): string
        {
            if ($prefix !== 'pdf_sealer') { throw new \RuntimeException('Unexpected module lookup'); }
            return 'v9.9.9';
        }
        public static function getConfig($prefix, $version = null, $pid = null, $translate = false): array
        {
            self::$configVersions[] = $version;
            return ['name' => 'PDF Sealer', 'description' => 'Test sealing operation'];
        }
        public static function getEnabledModules($projectId): array { return []; }
        public static function getProjectHeaderPath(): string { return self::$fixtureRoot . '/header.php'; }
        public static function getProjectFooterPath(): string { return self::$fixtureRoot . '/footer.php'; }
        public static function getManagerJSDirectory(): string { return 'js/'; }
        public static function addResource(string $path): void
        {
            echo '<script src="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"></script>';
        }
        public static function tt(string $key): string { return $key; }
    }
}

namespace {
    final class RCView
    {
        public static function escape(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }

    $frameworkRoot = getenv('PDF_FINALIZE_FRAMEWORK_ROOT') ?: dirname(__DIR__, 3) . '/external_modules';
    $source = $frameworkRoot . '/manager/activation-request.php';
    if (!is_file($source)) { throw new RuntimeException('Set PDF_FINALIZE_FRAMEWORK_ROOT to the Framework checkout'); }
    $fixtureRoot = sys_get_temp_dir() . '/pdf-activation-view-' . bin2hex(random_bytes(6));
    mkdir($fixtureRoot . '/manager/templates', 0700, true);
    \ExternalModules\ExternalModules::$fixtureRoot = $fixtureRoot;
    $files = [];
    try {
        foreach (['redcap_connect.php', 'header.php', 'footer.php', 'manager/templates/globals.php',
            'manager/templates/pdf-finalize-plan-modal.php'] as $relative) {
            $path = $fixtureRoot . '/' . $relative;
            file_put_contents($path, '<?php');
            $files[] = $path;
        }
        $page = $fixtureRoot . '/manager/activation-request.php';
        copy($source, $page);
        $files[] = $page;
        $_GET = ['pid' => '533', 'prefix' => 'pdf_sealer', 'request_id' => '14'];
        set_error_handler(static function ($severity, $message): never { throw new RuntimeException($message); });
        try {
            foreach (['', 'v0.0.1'] as $ambientVersion) {
                $html = (static function (string $page, string $ambientVersion): string {
                    $project_id = '533';
                    $version = $ambientVersion;
                    ob_start();
                    try { require $page; return ob_get_contents(); }
                    finally { ob_end_clean(); }
                })($page, $ambientVersion);
                foreach (['id="external-module-version" value="v9.9.9"',
                    'data-module="pdf_sealer" data-version="v9.9.9"',
                    'PDF Sealer - v9.9.9', 'js/project.js'] as $expected) {
                    if (!str_contains($html, $expected)) {
                        throw new RuntimeException('Approval page did not render its installed version: ' . $expected);
                    }
                }
            }
            if (\ExternalModules\ExternalModules::$configVersions !== ['v9.9.9', 'v9.9.9']) {
                throw new RuntimeException('Configuration and approval controls use different versions');
            }
        } finally { restore_error_handler(); }
        echo "Approval view uses the installed module version with empty or stale ambient version; no REDCap bootstrap, database, request completion or email.\n";
    } finally {
        foreach ($files as $path) { unlink($path); }
        rmdir($fixtureRoot . '/manager/templates'); rmdir($fixtureRoot . '/manager'); rmdir($fixtureRoot);
    }
}
