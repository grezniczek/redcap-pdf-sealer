<?php

declare(strict_types=1);

namespace PDFSealerBuild;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

const PREFIX = 'DE\\RUB\\PDFSealerExternalModule\\Dependencies\\';

function read(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Missing or unreadable file: $path");
    }
    return file_get_contents($path);
}

function json(string $path): array
{
    return json_decode(read($path), true, 512, JSON_THROW_ON_ERROR);
}

/** Relative paths and bytes, ordered for deterministic builds and tree hashes. */
function files(string $directory): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) {
            throw new RuntimeException("Symlink in dependency tree: $file");
        }
        if ($file->isFile()) {
            $files[substr($file->getPathname(), strlen($directory) + 1)] = read($file->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}

function digest(array $files): string
{
    ksort($files, SORT_STRING);
    $manifest = '';
    foreach ($files as $path => $contents) {
        $manifest .= hash('sha256', $contents) . "  $path\n";
    }
    return hash('sha256', $manifest);
}

/** Deliberately limited to the audited Tecnick packages, not a general PHP scoper. */
function prefixSource(string $source, string $date): string
{
    $output = '';
    $changed = false;
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (!is_array($token)) {
            $output .= $token;
            continue;
        }
        [$id, $text] = $token;
        if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && str_starts_with(ltrim($text, '\\'), 'Com\\Tecnick\\')) {
            $text = (str_starts_with($text, '\\') ? '\\' : '') . PREFIX . ltrim($text, '\\');
            $changed = true;
        } elseif (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && str_contains($text, 'Tecnick')) {
            throw new RuntimeException('New dynamic namespace string requires a build review');
        } elseif (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_DIR], true)) {
            throw new RuntimeException('New dependency loading/resource access requires a build review');
        } elseif (in_array($id, [T_COMMENT, T_DOC_COMMENT], true)) {
            // Keep copyright/license text; qualify PHPDoc class/type references too.
            $text = str_replace('Com\\Tecnick\\', PREFIX . 'Com\\Tecnick\\', $text);
        }
        $output .= $text;
    }
    if (!$changed || !str_starts_with($output, '<?php')) {
        throw new RuntimeException('Unexpected source without a Tecnick namespace');
    }
    $notice = "\n/*\n * Modified by the PDF Sealer project on $date:\n * PHP namespaces and references prefixed for dependency isolation.\n * See the package MODIFICATIONS.md. Original license retained.\n */\n";
    return substr_replace($output, $notice, 5, 0);
}

/** Return a complete reproducible bundle only after verifying all input packages. */
function build(string $root): array
{
    $review = json(__DIR__ . '/third-party-review.json');
    $lock = json($root . '/composer.lock');
    $packages = array_column($lock['packages'], null, 'name');
    $installed = array_column(json($root . '/vendor/composer/installed.json')['packages'], null, 'name');
    ksort($packages);
    ksort($installed);
    $names = array_keys($review['packages']);
    sort($names);
    if (array_keys($packages) !== $names || array_keys($installed) !== $names) {
        throw new RuntimeException('Locked, installed, and reviewed package sets differ');
    }
    $output = [];
    $manifest = ['namespace_prefix' => PREFIX, 'modified_on' => $review['modified_on'], 'packages' => []];
    foreach ($packages as $name => $package) {
        $audit = $review['packages'][$name];
        $input = files($root . '/vendor/' . $name);
        if ($package['source']['reference'] !== $audit['reference']
            || $installed[$name]['version'] !== $package['version']
            || $installed[$name]['source']['reference'] !== $audit['reference']
            || digest($input) !== $audit['tree_sha256']) {
            throw new RuntimeException("Unreviewed dependency input: $name");
        }
        $metadata = json_decode($input['composer.json'], true, 512, JSON_THROW_ON_ERROR);
        if ($metadata['name'] !== $name || (array) $metadata['license'] !== $package['license']) {
            throw new RuntimeException("Unexpected package metadata: $name");
        }
        foreach ($input as $path => $bytes) {
            if (str_starts_with($path, 'src/') && str_ends_with($path, '.php')) {
                $output["$name/$path"] = prefixSource($bytes, $review['modified_on']);
            } elseif (in_array($path, ['LICENSE', 'VERSION', 'SECURITY.md'], true)) {
                $output["$name/$path"] = $bytes;
            } elseif (!in_array($path, ['composer.json', 'README.md'], true)) {
                throw new RuntimeException("Unreviewed package file: $name/$path");
            }
        }
        $url = preg_replace('/\.git$/', '', $package['source']['url']);
        $output["$name/MODIFICATIONS.md"] = "# Modifications\n\nThis is a modified copy of `$name` version {$package['version']}.\n\nModified by the PDF Sealer project on {$review['modified_on']}.\n\nChanges from upstream:\n\n- PHP namespaces and references (including PHPDoc types) are prefixed with `" . PREFIX . "` to prevent collisions with other REDCap modules.\n- Each changed PHP file carries a dated modification notice. Original copyright and license notices are retained.\n- Composer metadata and the upstream installation README are omitted from this distribution. Runtime PHP source, VERSION, LICENSE, and SECURITY.md are retained. Loading is provided by PDF Sealer's own autoloader.\n- No intentional changes to library functionality.\n\nThe library remains licensed under LGPL-3.0-or-later. See [LICENSE](LICENSE) and the accompanying [GPLv3 text](../../../licenses/GPL-3.0.txt).\n\nUpstream: [$name]($url)\n\nSource revision: `{$package['source']['reference']}`\n";
        $manifest['packages'][$name] = [
            'version' => $package['version'], 'reference' => $package['source']['reference'],
            'upstream' => $url, 'license' => $package['license'],
            'authors' => array_column($metadata['authors'], 'name'), 'copyright' => $audit['copyright'],
            'license_sha256' => hash('sha256', $input['LICENSE']), 'modified' => true,
        ];
    }
    $output['manifest.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    ksort($output, SORT_STRING);
    return $output;
}
