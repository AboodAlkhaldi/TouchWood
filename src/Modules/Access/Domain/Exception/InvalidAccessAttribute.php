<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A malformed value: a role name, a store choice, an exception, an id.
 *
 * $rule names a refusal that has its own words for a person (`access::errors.invalid_access_attribute
 * .rules.{rule}`), where the general "isn't valid" would not say what to do: a phone from a country SMS
 * does not reach (amendment 87).
 */
final class InvalidAccessAttribute extends AccessError
{
    public function __construct(public readonly string $attribute, public readonly string $reason, public readonly ?string $rule = null)
    {
        parent::__construct("Invalid {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'access.invalid_access_attribute';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return $this->rule === null ? ['attribute' => $this->attribute] : ['attribute' => $this->attribute, 'rule' => $this->rule];
    }
}
