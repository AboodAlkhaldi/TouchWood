<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The newsletter box could not send its confirmation email (ops.md §4.9, §7): the sign-up stays and the
 * person may try again. Only the box answers with it, having reported the provider's failure to the logs:
 * a `DomainError` is never reported, and every other caller of a provider keeps `ProviderFailed` as it is.
 */
final class NewsletterUnavailable extends OpsError
{
    public function __construct()
    {
        parent::__construct('The newsletter box could not send its confirmation email.');
    }

    public function type(): string
    {
        return 'ops.newsletter_unavailable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
