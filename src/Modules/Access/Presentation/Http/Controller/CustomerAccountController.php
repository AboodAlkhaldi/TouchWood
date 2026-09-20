<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Modules\Access\Application\Command\VerifyCustomerEmail\VerifyCustomerEmail;
use Modules\Access\Application\Command\VerifyCustomerEmail\VerifyCustomerEmailHandler;

/**
 * A customer's own account on the storefront. Only the email verification link lives here for now;
 * signing in, the password and the rest of the account pages come with step 4b.
 */
final readonly class CustomerAccountController
{
    /**
     * The signed link from the verification email; the route's "signed" middleware has already
     * checked its signature and its expiry (spec §1.2, amendment 38).
     */
    public function verifyEmail(string $customer, VerifyCustomerEmailHandler $handler): RedirectResponse
    {
        $handler->handle(new VerifyCustomerEmail($customer));

        // The store middleware has taken its own parameters off the route and made them this
        // request's URL defaults, so the store's home page is named without repeating them.
        return redirect()->route('storefront.home')->with('status', __('access::auth.email_verified'));
    }
}
