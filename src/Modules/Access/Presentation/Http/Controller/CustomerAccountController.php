<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Modules\Access\Application\Command\VerifyCustomerEmail\VerifyCustomerEmail;
use Modules\Access\Application\Command\VerifyCustomerEmail\VerifyCustomerEmailHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\StoreContext;

/**
 * A customer's own account on the storefront. Only the email verification link lives here for now;
 * signing in, the password and the rest of the account pages come with step 4b.
 */
final readonly class CustomerAccountController
{
    /**
     * The signed link from the verification email; the route's "signed" middleware has already
     * checked its signature and its expiry (spec §1.2, amendment 38).
     *
     * **Not found in an off store, for everyone.** A Super Admin's staff view opens an off store's
     * pages to look (spec §1.11), and this link is opened like a page but writes - so it refuses
     * there itself, as it does for a visitor (the owner, 2026-10-06).
     */
    public function verifyEmail(string $customer, VerifyCustomerEmailHandler $handler, StoreContext $stores, PlatformApi $platform): RedirectResponse
    {
        if ($platform->store($stores->current())?->isActive !== true) {
            abort(404);
        }

        $handler->handle(new VerifyCustomerEmail($customer));

        // The store middleware has taken its own parameters off the route and made them this
        // request's URL defaults, so the store's home page is named without repeating them.
        return redirect()->route('storefront.home')->with('status', __('access::auth.email_verified'));
    }
}
