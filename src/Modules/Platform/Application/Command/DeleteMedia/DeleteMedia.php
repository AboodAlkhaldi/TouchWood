<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeleteMedia;

final readonly class DeleteMedia
{
    public function __construct(
        public string $mediaId,
    ) {}
}
