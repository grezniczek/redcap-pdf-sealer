<?php

declare(strict_types=1);

// Module-owned loader: only this module's namespace is registered.
spl_autoload_register(static function (string $class): void {
    $prefix = 'DE\\RUB\\PDFSealerExternalModule\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $maps = [
        'Dependencies\\Com\\Tecnick\\Pdf\\Filter\\' => 'libraries/tecnickcom/tc-lib-pdf-filter/src/',
        'Dependencies\\Com\\Tecnick\\Pdf\\Parser\\' => 'libraries/tecnickcom/tc-lib-pdf-parser/src/',
        'Dependencies\\Com\\Tecnick\\Pdf\\Sign\\' => 'libraries/tecnickcom/tc-lib-pdf-sign/src/',
        '' => 'src/',
    ];
    foreach ($maps as $namespace => $directory) {
        if (!str_starts_with($relative, $namespace)) {
            continue;
        }
        $suffix = substr($relative, strlen($namespace));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $suffix) !== 1) {
            return;
        }
        $path = __DIR__ . '/' . $directory . str_replace('\\', '/', $suffix) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
        return;
    }
});
