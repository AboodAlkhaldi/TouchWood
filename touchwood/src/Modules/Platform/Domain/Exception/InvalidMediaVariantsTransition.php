<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class InvalidMediaVariantsTransition extends PlatformError
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct("Image variants cannot go from {$from} to {$to}.");
    }

    public function type(): string
    {
        return 'platform.invalid_media_variants_transition';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }
}
