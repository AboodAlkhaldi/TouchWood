<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Modules\Platform\Public\Dto\MediaUseDto;
use Shared\Domain\Error\ErrorCategory;

/**
 * Media that must not be deleted: a module blocks it (a legal document), or — the database's
 * backstop — a reference still exists after every module detached it. The message tells the
 * person which records use it.
 */
final class MediaInUse extends PlatformError
{
    /**
     * @param  list<MediaUseDto>  $blockedBy  the uses that block the delete
     */
    public function __construct(
        public readonly string $mediaId,
        public readonly array $blockedBy = [],
    ) {
        parent::__construct("Media \"{$mediaId}\" is still used and cannot be deleted: {$this->uses()}.");
    }

    public function type(): string
    {
        return 'platform.media_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['id' => $this->mediaId, 'uses' => $this->uses()];
    }

    private function uses(): string
    {
        return implode(', ', array_map(fn (MediaUseDto $use): string => $use->describe(), $this->blockedBy));
    }
}
