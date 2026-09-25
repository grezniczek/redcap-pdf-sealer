<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use Com\Tecnick\Pdf\Parser\Parser;

final class PdfStructureInspector
{
    public function inspect(string $bytes): ExistingPdf
    {
        // Parser offsets are relative to its first %PDF header. Keep that header at byte zero.
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new UnsupportedPdf('PDF header must start at byte zero');
        }

        if (preg_match('/(?:\r\n|\r|\n)startxref\s+([0-9]+)\s+%%EOF\s*\z/D', $bytes, $match) !== 1) {
            throw new UnsupportedPdf('Missing final startxref');
        }
        $startXref = filter_var($match[1], FILTER_VALIDATE_INT);
        if ($startXref === false || $startXref <= 0 || $startXref >= strlen($bytes)) {
            throw new UnsupportedPdf('Invalid final startxref');
        }

        try {
            [$xref, $objects] = (new Parser(['decode_streams' => false, 'strict_limits' => true]))->parse($bytes);
        } catch (\Throwable $error) {
            throw new UnsupportedPdf('PDF structure cannot be resolved', 0, $error);
        }

        $trailer = $xref['trailer'];
        if (isset($trailer['encrypt'])) {
            throw new UnsupportedPdf('Encrypted PDFs are unsupported');
        }
        $rootRef = $trailer['root'] ?? '';
        $catalog = self::objectDictionary($objects, $rootRef, 'catalog');
        if (self::name(self::value($catalog, 'Type')) !== 'Catalog') {
            throw new UnsupportedPdf('Missing catalog type');
        }

        $perms = self::value($catalog, 'Perms');
        if ($perms !== null) {
            $permsDictionary = self::resolveDictionary($objects, $perms, 'permissions');
            if (self::value($permsDictionary, 'DocMDP') !== null) {
                throw new UnsupportedPdf('Existing DocMDP certification is unsupported');
            }
        }

        $pagesRef = self::reference(self::value($catalog, 'Pages'));
        $pageTree = self::objectDictionary($objects, $pagesRef, 'page tree');
        if (self::name(self::value($pageTree, 'Type')) !== 'Pages'
            || (self::value($pageTree, 'Kids')[0] ?? null) !== '['
            || !self::nonNegativeInteger(self::value($pageTree, 'Count'))) {
            throw new UnsupportedPdf('Invalid page tree');
        }

        [$firstPageRef, $firstPage] = self::firstPage($objects, $pagesRef, $pageTree);

        $acroFormToken = self::value($catalog, 'AcroForm');
        $acroForm = $acroFormToken === null ? null : self::resolveDictionary($objects, $acroFormToken, 'AcroForm');
        $acroFormRef = $acroFormToken === null ? null : self::reference($acroFormToken, false);

        $highest = 0;
        foreach (array_keys($xref['xref']) as $key) {
            if (preg_match('/^([0-9]+)_[0-9]+$/D', (string) $key, $parts) === 1) {
                $highest = max($highest, (int) $parts[1]);
            }
        }
        $size = $trailer['size'] ?? 0;
        if (!is_int($size) || $size < 1 || $highest >= PHP_INT_MAX - 1 || $size >= PHP_INT_MAX) {
            throw new UnsupportedPdf('Invalid trailer size or object number');
        }
        $nextObjectNumber = max($size - 1, $highest) + 1;

        $ids = $trailer['id'] ?? [];
        if ($ids !== [] && (count($ids) !== 2 || !self::isHex($ids[0]) || !self::isHex($ids[1]))) {
            throw new UnsupportedPdf('Invalid document ID');
        }
        $infoRef = ($trailer['info'] ?? '') ?: null;
        if ($infoRef !== null) {
            self::objectDictionary($objects, $infoRef, 'document info');
        }

        $hasSignatureFields = false;
        foreach ($objects as $object) {
            $dictionary = $object[0] ?? null;
            if (($dictionary[0] ?? null) === '<<'
                && (self::name(self::value($dictionary, 'FT')) === 'Sig'
                    || self::name(self::value($dictionary, 'Type')) === 'Sig')) {
                $hasSignatureFields = true;
                break;
            }
        }

        return new ExistingPdf(
            $bytes, $startXref, $nextObjectNumber, array_fill_keys(array_keys($xref['xref']), true), $rootRef, $infoRef, $ids,
            $catalog, $pagesRef, $pageTree, $firstPageRef, $firstPage, $acroForm, $acroFormRef, $hasSignatureFields,
        );
    }

    private static function firstPage(array $objects, string $pagesRef, array $pageTree): array
    {
        $currentRef = $pagesRef;
        $current = $pageTree;
        $seen = [];
        for ($depth = 0; $depth < 64; ++$depth) {
            if (isset($seen[$currentRef])) {
                throw new UnsupportedPdf('Cyclic page tree');
            }
            $seen[$currentRef] = true;
            if (self::name(self::value($current, 'Type')) === 'Page') {
                return [$currentRef, $current];
            }
            if (self::name(self::value($current, 'Type')) !== 'Pages') {
                throw new UnsupportedPdf('Invalid page tree node');
            }
            $kids = self::value($current, 'Kids');
            if (($kids[0] ?? null) !== '[' || !is_array($kids[1] ?? null) || $kids[1] === []) {
                throw new UnsupportedPdf('Empty or invalid page tree');
            }
            $currentRef = self::reference($kids[1][0]);
            $current = self::objectDictionary($objects, $currentRef, 'page tree node');
        }
        throw new UnsupportedPdf('Page tree depth exceeded');
    }

    public static function value(array $dictionary, string $key): ?array
    {
        if (($dictionary[0] ?? null) !== '<<' || !is_array($dictionary[1] ?? null)) {
            throw new UnsupportedPdf('Expected PDF dictionary');
        }
        $entries = $dictionary[1];
        if (count($entries) % 2 !== 0) {
            throw new UnsupportedPdf('Malformed PDF dictionary');
        }
        for ($index = 0; $index + 1 < count($entries); $index += 2) {
            if (($entries[$index][0] ?? null) === '/' && ($entries[$index][1] ?? null) === $key) {
                return $entries[$index + 1];
            }
        }
        return null;
    }

    private static function objectDictionary(array $objects, string $reference, string $label): array
    {
        if (!self::validReference($reference)) {
            throw new UnsupportedPdf('Invalid ' . $label . ' reference');
        }
        $dictionary = $objects[$reference][0] ?? null;
        if (!is_array($dictionary) || ($dictionary[0] ?? null) !== '<<') {
            throw new UnsupportedPdf('Unresolvable ' . $label . ' dictionary');
        }
        return $dictionary;
    }

    private static function resolveDictionary(array $objects, array $token, string $label): array
    {
        if (($token[0] ?? null) === '<<') {
            return $token;
        }
        return self::objectDictionary($objects, self::reference($token), $label);
    }

    private static function reference(?array $token, bool $required = true): ?string
    {
        if (($token[0] ?? null) !== 'objref' || !self::validReference($token[1] ?? '')) {
            if (!$required) {
                return null;
            }
            throw new UnsupportedPdf('Expected indirect reference');
        }
        return $token[1];
    }

    private static function validReference(mixed $reference): bool
    {
        return is_string($reference) && preg_match('/^[1-9][0-9]*_[0-9]+$/D', $reference) === 1;
    }

    private static function nonNegativeInteger(?array $token): bool
    {
        return ($token[0] ?? null) === 'numeric'
            && is_string($token[1] ?? null)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', $token[1]) === 1;
    }

    private static function name(?array $token): ?string
    {
        return ($token[0] ?? null) === '/' ? $token[1] : null;
    }

    private static function isHex(mixed $value): bool
    {
        return is_string($value) && strlen($value) % 2 === 0
            && preg_match('/^[0-9A-Fa-f]*$/D', $value) === 1;
    }
}
