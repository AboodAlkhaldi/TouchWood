<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Too short, or found in a known data breach (spec §1.8).
 */
final class PasswordTooWeak extends AccessError
{
    public const string TOO_SHORT = 'too_short';

    public const string LEAKED = 'leaked';

    /**
     * @param  self::TOO_SHORT|self::LEAKED  $reason
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $minLength,
    ) {
        parent::__construct($reason === self::LEAKED
            ? 'This password has appeared in a data breach; choose another.'
            : "A password needs at least {$minLength} characters.");
    }

    public function type(): string
    {
        return 'access.password_too_weak';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['reason' => $this->reason, 'min' => $this->minLength];
    }
}
