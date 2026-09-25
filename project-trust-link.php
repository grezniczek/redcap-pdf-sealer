<?php

declare(strict_types=1);

/** @var \DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule $module */
header('Location: ' . $module::publicTrustUrl(), true, 302);
exit;
