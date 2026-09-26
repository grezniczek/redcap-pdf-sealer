<?php

declare(strict_types=1);

// Also runs against an unpacked release with no vendor directory or Composer files.
$root = realpath(getenv('PDF_SEALER_PACKAGE_ROOT') ?: dirname(__DIR__));
if ($root === false) {
    throw new RuntimeException('Invalid module root');
}
if ($argc === 1) {
    foreach (['foreign-first', 'module-first'] as $order) {
        $process = proc_open([PHP_BINARY, __FILE__, $order], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not launch isolation check');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($output);
        }
        echo $output;
    }
    exit;
}
if (!in_array($argv[1], ['foreign-first', 'module-first'], true)) {
    throw new RuntimeException('Invalid load order');
}
$prefix = 'DE\\RUB\\PDFSealerExternalModule\\Dependencies\\';
$foreign = [];
foreach (['filter' => 'Filter', 'parser' => 'Parser', 'sign' => 'Sign'] as $package => $namespace) {
    $directory = "$root/libraries/tecnickcom/tc-lib-pdf-$package/src/";
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $suffix = substr($file->getPathname(), strlen($directory), -4);
            $foreign[] = "Com\\Tecnick\\Pdf\\$namespace\\" . str_replace('/', '\\', $suffix);
        }
    }
}
// Deliberately incompatible stand-ins for another EM's classes, including every
// upstream name. PDF Sealer must neither load nor depend on these implementations.
spl_autoload_register(static function (string $class) use ($foreign): void {
    if (in_array($class, $foreign, true)) {
        $split = strrpos($class, '\\');
        $namespace = substr($class, 0, $split);
        $name = substr($class, $split + 1);
        eval("namespace $namespace; class $name { public const FOREIGN = true; }");
    }
});
$loadForeign = static function () use ($foreign): void {
    foreach ($foreign as $class) {
        if (!class_exists($class) || !constant($class . '::FOREIGN')) {
            throw new RuntimeException("Foreign symbol changed: $class");
        }
    }
};
if ($argv[1] === 'foreign-first') {
    $loadForeign();
}
require_once $root . '/autoload.php';
foreach ($foreign as $class) {
    $owned = $prefix . $class;
    if (!class_exists($owned) && !interface_exists($owned)) {
        throw new RuntimeException("Bundled symbol missing: $owned");
    }
    if (!str_starts_with((new ReflectionClass($owned))->getFileName(), $root . '/libraries/')) {
        throw new RuntimeException("Bundled symbol loaded from wrong location: $owned");
    }
    if ($argv[1] === 'module-first' && class_exists($class, false)) {
        throw new RuntimeException("Bundled loader leaked upstream symbol: $class");
    }
}
$loadForeign();
$asn1 = new DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Asn1();
if ($asn1->encodeInteger(42) !== "\x02\x01\x2a") {
    throw new RuntimeException('Bundled ASN.1 implementation failed');
}
$policy = new DE\RUB\PDFSealerExternalModule\Timestamp\PolicyOidAsn1(
    DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy::DEFAULT_OID,
);
if (!$policy instanceof DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Asn1
    || class_exists('Composer\\Autoload\\ClassLoader', false)) {
    throw new RuntimeException('Module adapter or loader uses an external dependency');
}
echo 'Dependency isolation: ' . $argv[1] . ', ' . count($foreign) . " bundled symbols and module adapter passed.\n";
