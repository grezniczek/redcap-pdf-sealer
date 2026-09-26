<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/dependency-build.php';

try {
    if ($argc > 2 || ($argc === 2 && $argv[1] !== '--write')) {
        throw new RuntimeException('Usage: php tools/build-dependencies.php [--write]');
    }
    $root = dirname(__DIR__);
    $expected = PDFSealerBuild\build($root);
    if (($argv[1] ?? '') === '--write') {
        foreach ($expected as $path => $bytes) {
            $target = $root . '/libraries/' . $path;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
                throw new RuntimeException("Could not create directory for $target");
            }
            if (is_link($target) || file_put_contents($target, $bytes) !== strlen($bytes)) {
                throw new RuntimeException("Could not write $target");
            }
        }
    }
    if (PDFSealerBuild\files($root . '/libraries') !== $expected) {
        throw new RuntimeException('Bundled dependencies differ from the reproducible build (or contain extra files)');
    }
    echo 'Verified reproducible prefixed bundle: ' . count($expected) . " files.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
