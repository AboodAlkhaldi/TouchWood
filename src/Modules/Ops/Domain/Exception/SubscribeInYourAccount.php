<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The shop's newsletter box is a guest's (ops.md §1.9, §5): an email that belongs to a customer
 * account, or a customer signed in, is told to tick "Send me offers and news" in the account instead
 * (§1.3) - their consent is the account's switch, not a guest's sign-up.
 */
final class SubscribeInYourAccount extends OpsError
{
    public function __construct()
    {
        parent::__construct('This email belongs to an account: switch on offers and news in the account instead.');
    }

    public function type(): string
    {
        return 'ops.subscribe_in_your_account';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
