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
    /** An old address that is no path of this site: empty, the front page, another host, not text. */
    public const string OLD_PATH = 'old_path';

    /** An old address beginning with a store's code, on or off: the new site answers there. */
    public const string OLD_STORE = 'old_store';

    /** An old address beginning with a reserved path (`/admin`) or one of the site's own files. */
    public const string OLD_RESERVED = 'old_reserved';

    /** A new address that is not a full shop path, `/{store}/{locale}/...`, with no query string. */
    public const string NEW_PATH = 'new_path';

    /** A new address on no store. */
    public const string NEW_STORE = 'new_store';

    /** An old address another line holds already. */
    public const string TAKEN = 'taken';

    /** An old address written twice in one sheet: the second is refused. */
    public const string TWICE = 'twice';

    /** Use Final Address on a line whose new address is not marked as forwarding. */
    public const string NOT_FORWARDING = 'not_forwarding';

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
