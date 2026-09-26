<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/dependency-build.php';

try {
    $write = ($argv[1] ?? '') === '--write';
    if ($argc > 2 || (!$write && $argc === 2 && !is_dir($argv[1]))) {
        throw new RuntimeException('Usage: php tools/third-party-notices.php [--write | /path/to/unpacked-release]');
    }
    $root = (!$write && $argc === 2) ? realpath($argv[1]) : dirname(__DIR__);
    $review = PDFSealerBuild\json(__DIR__ . '/third-party-review.json');
    $manifest = PDFSealerBuild\json($root . '/libraries/manifest.json');
    $packages = $manifest['packages'];
    $lock = array_column(PDFSealerBuild\json(dirname(__DIR__) . '/composer.lock')['packages'], null, 'name');
    $names = array_keys($packages);
    $reviewedNames = array_keys($review['packages']);
    $lockedNames = array_keys($lock);
    sort($names);
    sort($reviewedNames);
    sort($lockedNames);
    if ($names !== $reviewedNames || $names !== $lockedNames
        || $manifest['namespace_prefix'] !== PDFSealerBuild\PREFIX
        || $manifest['modified_on'] !== $review['modified_on']) {
        throw new RuntimeException('Locked, bundled, and reviewed dependency metadata differ');
    }
    foreach ($review['license_sha256'] as $path => $hash) {
        if (hash('sha256', PDFSealerBuild\read($root . '/' . $path)) !== $hash) {
            throw new RuntimeException("Reviewed license text changed: $path");
        }
    }
    foreach ($packages as $name => $package) {
        $directory = $root . '/libraries/' . $name;
        if ($package['reference'] !== $review['packages'][$name]['reference']
            || $package['reference'] !== $lock[$name]['source']['reference']
            || $package['version'] !== $lock[$name]['version'] || $package['license'] !== $lock[$name]['license']
            || !$package['modified']) {
            throw new RuntimeException("Unexpected bundled package metadata: $name");
        }
        if (hash('sha256', PDFSealerBuild\read($directory . '/LICENSE')) !== $package['license_sha256']) {
            throw new RuntimeException("Upstream license changed: $name");
        }
        PDFSealerBuild\read($directory . '/MODIFICATIONS.md');
    }
    if (PDFSealerBuild\digest(PDFSealerBuild\files($root . '/libraries')) !== $review['bundled_tree_sha256']) {
        throw new RuntimeException('Bundled files differ from the reviewed prefixed build');
    }
    PDFSealerBuild\read($root . '/autoload.php');
    // A release must be self-contained, with no Composer build inputs or runtime.
    if ($root !== realpath(dirname(__DIR__))) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, 'tools/')
                || in_array(strtolower($file->getFilename()), ['composer.json', 'composer.lock', 'composer.phar'], true)) {
                throw new RuntimeException("Development/Composer file in release: $relative");
            }
        }
    }
    $notice = "# Third-party notices\n\nPDF Sealer is licensed under the MIT License. This distribution also includes third-party software that is licensed separately. The licenses listed below apply to their respective components and not to PDF Sealer as a whole.\n\n";
    $notice .= "PDF Sealer uses the libraries below under LGPL-3.0-or-later. Their original license texts and copyright notices are retained alongside their complete PHP source. The accompanying [GNU GPL version 3](licenses/GPL-3.0.txt) is also included, as required by LGPLv3 section 4(b). Neither these notices nor PDF Sealer's MIT license restrict modification of the libraries or reverse engineering to debug those modifications. The libraries remain separate, replaceable PHP sources in `libraries/`; the module source and its own autoloader are supplied too.\n\n";
    foreach ($packages as $name => $package) {
        $path = 'libraries/' . $name;
        $authors = implode('; ', $package['authors']);
        $license = implode(' OR ', $package['license']);
        $notice .= "## $name\n\n- Version: {$package['version']}\n- Upstream: [$name]({$package['upstream']})\n- Source revision: `{$package['reference']}`\n- License: $license\n- Author: $authors\n- Copyright: {$package['copyright']}\n- Original license: [$path/LICENSE]($path/LICENSE)\n- Bundled copy: modified; see [$path/MODIFICATIONS.md]($path/MODIFICATIONS.md).\n\n";
    }
    $notice .= "## Distribution scope\n\nThe libraries' PHP namespaces and references are prefixed with `" . PDFSealerBuild\PREFIX . "` to isolate them from other REDCap modules. Modified source files carry dated notices; package-level modification records describe the changes and omitted upstream installation metadata. No intentional functional changes were made.\n\nComposer is used only during development to obtain pinned upstream source. No Composer autoloader, runtime, manifests, or lockfile is included in the module distribution. PHP and its extensions, REDCap, the External Module Framework, and development validation tools such as qpdf and Poppler are host dependencies, not bundled components. No third-party browser library is bundled.\n";
    if ($write) {
        if (file_put_contents($root . '/THIRD_PARTY_NOTICES.md', $notice) === false) {
            throw new RuntimeException('Could not write THIRD_PARTY_NOTICES.md');
        }
    } elseif (PDFSealerBuild\read($root . '/THIRD_PARTY_NOTICES.md') !== $notice) {
        throw new RuntimeException('THIRD_PARTY_NOTICES.md is stale; regenerate after reviewing dependencies');
    }
    echo 'Verified ' . count($packages) . " prefixed LGPL packages and their notices; module-owned autoloader.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
