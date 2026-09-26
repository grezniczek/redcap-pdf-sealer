<?php
/*
 * Modified by the PDF Sealer project on 2026-09-27:
 * PHP namespaces and references prefixed for dependency isolation.
 * See the package MODIFICATIONS.md. Original license retained.
 */


declare(strict_types=1);

/**
 * Template.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFilter
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-filter
 *
 * This file is part of tc-lib-pdf-filter software library.
 */

namespace DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Filter\Type;

/**
 * DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Filter\Type\Template
 *
 * Interface implemented by all filter decoders.
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFilter
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-filter
 */
interface Template
{
    /**
     * Decode the data.
     *
     * @param string               $data   Data to decode.
     * @param array<string, mixed> $params Optional DecodeParms dictionary.
     *
     * @return string Decoded data.
     */
    public function decode(string $data, array $params = []): string;
}
