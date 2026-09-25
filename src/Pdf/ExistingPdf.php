<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

/** Parsed structure needed to append a revision without changing the source bytes. */
final class ExistingPdf
{
    public function __construct(
        public readonly string $bytes,
        public readonly int $startXref,
        public readonly int $nextObjectNumber,
        public readonly array $objectRefs,
        public readonly string $rootRef,
        public readonly ?string $infoRef,
        public readonly array $documentIds,
        public readonly array $catalog,
        public readonly string $pagesRef,
        public readonly array $pageTree,
        public readonly ?array $permissions,
        public readonly ?string $permissionsRef,
        public readonly array $indirectArrays,
        public readonly string $firstPageRef,
        public readonly array $firstPage,
        public readonly ?array $acroForm,
        public readonly ?string $acroFormRef,
        public readonly bool $hasSignatureFields,
        public readonly bool $hasExistingSignatures,
    ) {
    }
}
