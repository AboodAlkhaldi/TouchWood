<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * One place a module uses a piece of media, as reported by its MediaUsage.
 */
final readonly class MediaUseDto
{
    /**
     * @param  string  $subjectType  "{module}.{resource}", e.g. "catalog.product", as in audit entries
     * @param  bool  $blocksDelete  true when the media must not be deleted while this use exists
     */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public bool $blocksDelete,
    ) {}

    public function describe(): string
    {
        return "{$this->subjectType} {$this->subjectId}";
    }
}
