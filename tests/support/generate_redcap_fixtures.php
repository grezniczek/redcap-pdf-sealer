<?php

declare(strict_types=1);

// Separate process keeps fixture-only globals/classes out of a live REDCap bootstrap.
require __DIR__ . '/pdf_seal_checks.php';
require __DIR__ . '/redcap_pdf_fixtures.php';
checkSeal($argc === 3 && str_starts_with($argv[2], '/') && is_dir($argv[2])
    && glob($argv[2] . '/*') === [], 'Supply a Core directory and an empty absolute output directory');
echo json_encode(createRedcapFixtures($argv[1], $argv[2]), JSON_THROW_ON_ERROR);
