<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A redirect against §1.15: an old address that is a store's or reserved, a new address of no store
 * (content.md §7), naming the rule.
 */
final class RedirectInvalid extends ContentError
{
    /**
     * @param  string  $rule  the rule, as its words are keyed
     */
    public function __construct(
        public readonly string $rule,
    ) {
        parent::__construct("The redirect is not valid ({$rule}).");
    }

    public function type(): string
    {
        return 'content.redirect_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['rule' => $this->rule];
    }
}
