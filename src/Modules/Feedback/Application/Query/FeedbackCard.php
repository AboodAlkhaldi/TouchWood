<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/**
 * A product as Feedback's pages show it (catalog.md amendment 26(a)): its name and address in the
 * reader's language, its first photo (size slug => format => URL), and
 * whether the store it is read in sells it now (answer 19). `removed` marks one Catalog no longer has.
 */
final readonly class FeedbackCard
{
    /**
     * @param  array<string, array<string, string>>|null  $photo
     */
    public function __construct(
        public string $productId,
        public string $name,
        public ?string $slug,
        public ?array $photo,
        public bool $sold,
        public bool $archived,
        public bool $removed = false,
    ) {}
}
