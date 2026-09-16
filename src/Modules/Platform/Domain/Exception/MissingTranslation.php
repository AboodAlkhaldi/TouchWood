<?php

namespace Modules\Platform\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class MissingTranslation extends PlatformError
{
    public function __construct(public readonly string $attribute)
    {
        parent::__construct("The {$attribute} needs both an Arabic and an English value.");
    }

    public function type(): string
    {
        return 'platform.missing_translation';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}
