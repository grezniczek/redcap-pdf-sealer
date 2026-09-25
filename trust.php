<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\PublicTrustRepository;

/** @var \DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule $module */
$framework = $module->framework;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$unavailable = false;
$roots = [];
$activeId = null;
try {
    $repository = new PublicTrustRepository(
        new PrimaryLogReader($framework),
        new PrimarySystemSettingReader($framework),
    );
    $roots = $repository->roots();
    $activeId = $repository->activeRootId();
} catch (Throwable $e) {
    $unavailable = true;
    http_response_code(503);
}
$current = null;
$otherRoots = [];
foreach ($roots as $root) {
    if ($root['id'] === $activeId) {
        $current = $root;
    } else {
        $otherRoots[] = $root;
    }
}
$displayRoots = $current === null ? $otherRoots : array_merge([$current], $otherRoots);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($framework->tt('trust_page_title')) ?></title>
    <style>
        body { max-width: 860px; margin: 2rem auto; padding: 0 1rem; font: 16px/1.5 system-ui, sans-serif; color: #222; }
        h1, h2 { line-height: 1.25; }
        .certificate { border: 1px solid #ccc; border-radius: 4px; padding: 1rem; margin: 1.25rem 0; }
        dt { font-weight: 600; margin-top: .75rem; }
        dd { margin-left: 0; overflow-wrap: anywhere; }
        code { overflow-wrap: anywhere; }
        button { margin: .5rem .5rem .25rem 0; padding: .4rem .7rem; cursor: pointer; }
        .notice { border-left: 4px solid #777; padding: .5rem .8rem; background: #f5f5f5; }
        .error { border-left-color: #a33; }
    </style>
</head>
<body>
    <main>
        <h1><?= $escape($framework->tt('trust_page_title')) ?></h1>
        <p><?= $escape($framework->tt('trust_intro')) ?></p>
        <p class="notice"><?= $escape($framework->tt('trust_validation_note')) ?></p>
        <?php if ($unavailable): ?>
            <p class="notice error"><?= $escape($framework->tt('trust_unavailable')) ?></p>
        <?php else: ?>
            <?php if ($current === null): ?>
                <p class="notice"><?= $escape($framework->tt('trust_no_current_root')) ?></p>
            <?php endif; ?>
            <?php if ($displayRoots === []): ?>
                <p><?= $escape($framework->tt('trust_no_roots')) ?></p>
            <?php endif; ?>
            <?php foreach ($displayRoots as $root): ?>
                <section class="certificate">
                    <h2><?= $escape($framework->tt($root['id'] === $activeId ? 'trust_current_root' : 'trust_other_roots')) ?></h2>
                    <dl>
                        <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd><?= $escape($root['subject']) ?></dd>
                        <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code><?= $escape($root['fingerprint']) ?></code></dd>
                        <dt><?= $escape($framework->tt('trust_valid_from')) ?></dt><dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $root['valid_from'])) ?></dd>
                        <dt><?= $escape($framework->tt('trust_valid_until')) ?></dt><dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $root['valid_until'])) ?></dd>
                    </dl>
                    <button type="button" data-root-id="<?= $escape($root['id']) ?>" data-root-format="pem"><?= $escape($framework->tt('trust_download_pem')) ?></button>
                    <button type="button" data-root-id="<?= $escape($root['id']) ?>" data-root-format="der"><?= $escape($framework->tt('trust_download_der')) ?></button>
                </section>
            <?php endforeach; ?>
            <p id="trust-download-message" class="notice error" role="status" hidden></p>
        <?php endif; ?>
        <h2><?= $escape($framework->tt('trust_tsa_title')) ?></h2>
        <p><?= $escape($framework->tt('trust_tsa_description')) ?></p>
    </main>
    <?php $framework->initializeJavascriptModuleObject(); ?>
    <script>
    (() => {
        const module = <?= $framework->getJavascriptModuleObjectName() ?>;
        const message = document.getElementById('trust-download-message');
        const failedMessage = <?= json_encode($framework->tt('pki_root_download_unavailable'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.querySelectorAll('[data-root-id][data-root-format]').forEach(button => {
            button.addEventListener('click', () => {
                button.disabled = true;
                message.hidden = true;
                module.ajax('download_public_root_certificate', {
                    id: button.dataset.rootId,
                    format: button.dataset.rootFormat
                }).then(response => {
                    if (!response || !response.ok) {
                        message.textContent = response && response.message ? response.message : failedMessage;
                        message.hidden = false;
                        return;
                    }
                    const binary = atob(response.base64);
                    const bytes = new Uint8Array(binary.length);
                    for (let index = 0; index < binary.length; index++) {
                        bytes[index] = binary.charCodeAt(index);
                    }
                    const url = URL.createObjectURL(new Blob([bytes], {type: response.content_type}));
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = response.filename;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setTimeout(() => URL.revokeObjectURL(url), 60000);
                }).catch(() => {
                    message.textContent = failedMessage;
                    message.hidden = false;
                }).finally(() => {
                    button.disabled = false;
                });
            });
        });
    })();
    </script>
</body>
</html>
