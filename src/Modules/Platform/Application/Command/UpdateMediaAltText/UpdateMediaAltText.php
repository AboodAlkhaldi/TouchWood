<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\UpdateMediaAltText;

final readonly class UpdateMediaAltText
{
    /**
     * Null or empty text clears that language's alt text.
     */
    public function __construct(
        public string $mediaId,
        public ?string $altAr,
        public ?string $altEn,
    ) {}
}
