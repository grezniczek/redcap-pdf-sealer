<?php

declare(strict_types=1);

// Development-only: never bootstrap REDCap or execute the inspected vendor code.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function readJson(string $path): array
{
    return json_decode(readFileRequired($path), true, 512, JSON_THROW_ON_ERROR);
}

function readFileRequired(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Missing or unreadable file: $path");
    }
    return file_get_contents($path);
}

function packageDigest(string $directory): string
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) {
            throw new RuntimeException("Symlink in dependency: $file");
        }
        if ($file->isFile()) {
            $files[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    $manifest = '';
    foreach ($files as $path => $hash) {
        $manifest .= "$hash  $path\n";
    }
    return hash('sha256', $manifest);
}

try {
    $write = ($argv[1] ?? '') === '--write';
    if ($argc > 2 || (!$write && $argc === 2 && !is_dir($argv[1]))) {
        throw new RuntimeException('Usage: php tools/third-party-notices.php [--write | /path/to/unpacked-release]');
    }
    $root = (!$write && $argc === 2) ? realpath($argv[1]) : dirname(__DIR__);
    $review = readJson(__DIR__ . '/third-party-review.json');
    $lock = readJson($root . '/composer.lock');
    $installed = readJson($root . '/vendor/composer/installed.json');
    $packages = array_column($lock['packages'], null, 'name');
    $actual = array_column($installed['packages'], null, 'name');
    ksort($packages);
    ksort($actual);
    $reviewedNames = array_keys($review['packages']);
    sort($reviewedNames);
    if (array_keys($packages) !== array_keys($actual) || array_keys($packages) !== $reviewedNames) {
        throw new RuntimeException('Locked, installed, and reviewed production packages differ. Review dependency changes first.');
    }
    foreach ($review['license_sha256'] + $review['runtime_sha256'] as $path => $hash) {
        if (hash('sha256', readFileRequired($root . '/' . $path)) !== $hash) {
            throw new RuntimeException("Reviewed license or runtime file changed: $path");
        }
    }
    readFileRequired($root . '/vendor/autoload.php');
    readFileRequired($root . '/vendor/composer/ClassLoader.php');
    readFileRequired($root . '/vendor/composer/InstalledVersions.php');
    // Catch libraries copied into vendor without being registered in Composer.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/vendor', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) {
            throw new RuntimeException("Symlink in vendor: $file");
        }
        $relative = substr($file->getPathname(), strlen($root . '/vendor/'));
        if ($relative === 'autoload.php' || str_starts_with($relative, 'composer/')) {
            continue;
        }
        $parts = explode('/', $relative);
        if (!isset($packages[implode('/', array_slice($parts, 0, 2))])) {
            throw new RuntimeException("Unlisted vendor content: $relative");
        }
    }

    $notice = "# Third-party notices\n\nPDF Sealer is licensed under the MIT License. This distribution also includes third-party software that is licensed separately. The licenses listed below apply to their respective components and not to PDF Sealer as a whole.\n\n";
    $notice .= "PDF Sealer uses the libraries below under LGPL-3.0-or-later. Their original license texts and copyright notices are retained alongside their complete PHP source. The accompanying [GNU GPL version 3](licenses/GPL-3.0.txt) is also included, as required by LGPLv3 section 4(b). Neither these notices nor PDF Sealer's MIT license restrict modification of the libraries or reverse engineering to debug those modifications. The libraries remain separate, replaceable PHP sources in `vendor/`; the module source is supplied too.\n\n";
    foreach ($packages as $name => $package) {
        $audit = $review['packages'][$name];
        $path = 'vendor/' . $name;
        $metadata = readJson($root . '/' . $path . '/composer.json');
        readFileRequired($root . '/' . $path . '/LICENSE');
        if ($actual[$name]['version'] !== $package['version']
            || $actual[$name]['source']['reference'] !== $package['source']['reference']
            || $audit['reference'] !== $package['source']['reference']
            || $metadata['name'] !== $name
            || (array) $metadata['license'] !== $package['license']) {
            throw new RuntimeException("Dependency metadata changed: $name");
        }
        if ($audit['modified']) {
            readFileRequired($root . '/' . $path . '/MODIFICATIONS.md');
        }
        if (packageDigest($root . '/' . $path) !== $audit['tree_sha256']) {
            throw new RuntimeException("Dependency files changed: $name. Audit source and modification notices before updating its review fingerprint.");
        }
        $url = preg_replace('/\.git$/', '', $package['source']['url']);
        $authors = implode('; ', array_column($metadata['authors'] ?? [], 'name'));
        $license = implode(' OR ', $package['license']);
        $notice .= "## $name\n\n- Version: {$package['version']}\n- Upstream: [$name]($url)\n- Source revision: `{$package['source']['reference']}`\n- License: $license\n- Author: $authors\n- Copyright: {$audit['copyright']}\n- Original license: [$path/LICENSE]($path/LICENSE)\n";
        $notice .= $audit['modified']
            ? "- Bundled copy: modified; see [$path/MODIFICATIONS.md]($path/MODIFICATIONS.md).\n\n"
            : "- Bundled copy: unmodified (including original namespaces).\n\n";
    }
    $notice .= "## Composer autoloader and runtime\n\n- Upstream: [Composer](https://github.com/composer/composer)\n- Version: generated installation support; Composer does not record the generator version in the shipped metadata. These files are not a separately locked package.\n- License: MIT\n- Copyright: Nils Adermann, Jordi Boggiano\n- ClassLoader authors: Fabien Potencier, Jordi Boggiano\n- Original license: [vendor/composer/LICENSE](vendor/composer/LICENSE)\n- Bundled copy: Composer-generated autoload maps and installation metadata, with upstream runtime classes; no PDF Sealer source edits.\n\n";
    $notice .= "## Scope and maintenance\n\nPHP and its extensions, REDCap, the External Module Framework, and development validation tools such as qpdf and Poppler are host dependencies, not bundled components of this distribution. No third-party browser library is bundled.\n\nPackage contents were compared byte-for-byte with the Composer source-distribution archives for the pinned revisions on {$review['reviewed_on']}. No library is namespace-prefixed or otherwise modified, so no library modification notices are needed. PDF Sealer's `PolicyOidAsn1` adapter resides in the module's own source; the upstream library remains unchanged.\n\nThis file is generated by `php tools/third-party-notices.php --write` after dependency review. The default command verifies notices, installed versions, original license texts, and reviewed package contents. Development tooling is omitted from release ZIPs.\n";
    if ($write) {
        if (file_put_contents($root . '/THIRD_PARTY_NOTICES.md', $notice) === false) {
            throw new RuntimeException('Could not write THIRD_PARTY_NOTICES.md');
        }
    } elseif (readFileRequired($root . '/THIRD_PARTY_NOTICES.md') !== $notice) {
        throw new RuntimeException('THIRD_PARTY_NOTICES.md is stale; regenerate after reviewing dependencies.');
    }
    echo ($write ? 'Generated' : 'Verified') . ' third-party notices: ' . count($packages) . " packages and Composer runtime.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
