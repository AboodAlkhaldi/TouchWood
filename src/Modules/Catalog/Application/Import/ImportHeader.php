<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * An uploaded file's own row (catalog.md §5.5): what it is and where it stands.
 */
final readonly class ImportHeader
{
    public const string PRODUCTS = 'PRODUCTS';

    public const string STORE_FILL = 'STORE_FILL';

    public const string DECIDING = 'DECIDING';

    public const string BRINGING_IN = 'BRINGING_IN';

    public const string IN = 'IN';

    public const string FAILED = 'FAILED';

    public const string OPEN = 'OPEN';

    public function __construct(
        public string $id,
        public string $kind,
        public ?string $storeId,
        public string $fileName,
        public ?string $archive,
        public string $state,
        public ?string $failure,
        public ?string $uploadedBy = null,
        public ?string $uploadedAt = null,
    ) {}

    /** A products file whose names and codes may still be decided: before it is brought in, or after that failed. */
    public function isDeciding(): bool
    {
        return $this->kind === self::PRODUCTS && in_array($this->state, [self::DECIDING, self::FAILED], true);
    }
}
