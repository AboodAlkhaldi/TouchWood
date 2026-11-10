<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Translate gave no English (content.md §1.3, §7): the token isn't set, the service failed for one of
 * Platform's reasons, or the press is past one of the limits. Each reason has its own words under the box;
 * what is in the box is kept.
 */
final class TranslationUnavailable extends ContentError
{
    /** No token on the server: "Translation isn't set up on this server." */
    public const string NOT_SET = 'not_set';

    /** DeepL's 456: this month's allowance is used up. */
    public const string QUOTA = 'quota';

    /** 429 or 529 from the service, or this person's 30 presses a minute: "Try again in a minute." */
    public const string BUSY = 'busy';

    /** A bad token (403), or a call the service would not take. */
    public const string REFUSED = 'refused';

    /** A timeout, or no usable answer. */
    public const string UNREACHABLE = 'unreachable';

    /** One press over 20,000 characters: nothing was sent (§1.3). */
    public const string TOO_LONG = 'too_long';

    /** This person's 300,000 characters a day used up (§1.3). */
    public const string DAILY_LIMIT = 'daily_limit';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct("Translation unavailable ({$reason}).");
    }

    public function type(): string
    {
        return 'content.translation_unavailable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['rule' => $this->reason];
    }
}
