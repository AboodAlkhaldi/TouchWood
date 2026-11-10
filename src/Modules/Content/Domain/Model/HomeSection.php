<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * One section of a store's home page (content.md §1.4): its type, its place, shown or hidden, and the words staff
 * filled (`settings`, in `HomeSectionSettings`' shape). The client logos' files are `HomeLogo` rows of their own.
 */
final readonly class HomeSection
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public HomeSectionType $type,
        public int $position,
        public bool $isVisible,
        public array $settings,
        public int $version,
        public ?string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {}
}
