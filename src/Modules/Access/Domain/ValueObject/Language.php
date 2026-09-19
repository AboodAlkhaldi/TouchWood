<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;

/**
 * A person's communication language: every email and SMS code to them uses it (owner's decision,
 * 2026-09-19). The panel's display language is chosen separately, per session.
 */
enum Language: string
{
    case Arabic = 'ar';
    case English = 'en';

    public static function of(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? throw new InvalidAccessAttribute('locale', 'expected ar or en');
    }
}
