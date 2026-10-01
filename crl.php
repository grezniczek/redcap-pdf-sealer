<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\CrlRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\PublicCrlService;
use DE\RUB\PDFSealerExternalModule\Pki\PublicTrustRepository;

/** @var \DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule $module */
if (!defined('PDF_SEALER_PUBLIC_CRL_ROUTE')) {
    http_response_code(404);
    exit;
}

$settings = new PrimarySystemSettingReader($module->framework);
$response = (new PublicCrlService(
    new PublicTrustRepository(new PrimaryLogReader($module->framework), $settings),
    new CrlRepository($module->framework, $settings),
))->response($crlKeyId);
http_response_code($response['status']);
header_remove('Pragma');
header_remove('Expires');
foreach ($response['headers'] as $name => $value) { header($name . ': ' . $value); }
header('Content-Length: ' . strlen($response['body']));
echo $response['body'];
