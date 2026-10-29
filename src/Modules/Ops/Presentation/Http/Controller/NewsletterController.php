<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Ops\Application\Newsletter\NewsletterSignUps;
use Modules\Ops\Domain\Exception\SubscribeInYourAccount;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\StoreContext;

/*
| The shop's newsletter box and its links (ops.md §1.9, §3: guest / signed link). JSON; the screens
| agent builds the box and the pages the links open.
|
| - POST newsletter: a guest gives an email. The same answer whether it was on the list or not; a
|   signed-in customer, or an email that belongs to an account, is told to tick the box in the
|   account instead (409). At most 5 sign-ups an hour
|   for one email and 20 from one address: the box sends emails to whatever is typed in it.
| - GET newsletter/confirm/{token}: what the confirmation link opens; POST confirms. Opening a link
|   confirms nothing - mail scanners open links too.
| - GET newsletter/leave/{subscriber}: a guest's link from the confirmation email, signed on its path:
|   the guest is taken off at once (§3).
| - GET unsubscribe/{customer}: a marketing email's link, signed: the consent is withdrawn at once,
|   with no sign-in (§1.3).
*/
final readonly class NewsletterController
{
    public const int SIGN_UPS_AN_HOUR_FOR_AN_EMAIL = 5;

    public const int SIGN_UPS_AN_HOUR_FROM_AN_ADDRESS = 20;

    public function __construct(
        private NewsletterSignUps $signUps,
    ) {}

    public function subscribe(Request $request, StoreContext $stores, PlatformApi $platform, ActorContext $actors): JsonResponse
    {
        if ($actors->current()->type === ActorType::Customer) {
            throw new SubscribeInYourAccount;
        }

        $email = Inputs::text($request, 'email') ?? '';

        foreach (['ops-newsletter-ip:'.$request->ip() => self::SIGN_UPS_AN_HOUR_FROM_AN_ADDRESS, 'ops-newsletter-email:'.hash('sha256', mb_strtolower($email)) => self::SIGN_UPS_AN_HOUR_FOR_AN_EMAIL] as $key => $most) {
            if (RateLimiter::tooManyAttempts($key, $most)) {
                return new JsonResponse(['retryAfter' => RateLimiter::availableIn($key)], 429);
            }

            RateLimiter::hit($key, 3600);
        }

        $store = $platform->store($stores->current()) ?? abort(404);
        $this->signUps->subscribe($store, app()->getLocale(), $email);

        return new JsonResponse(['sent' => true], 202);
    }

    public function confirmation(string $token): JsonResponse
    {
        return new JsonResponse(['token' => $token]);
    }

    public function confirm(string $token): JsonResponse
    {
        $this->signUps->confirm($token);

        return new JsonResponse(['confirmed' => true]);
    }

    public function leave(string $subscriber): JsonResponse
    {
        $this->signUps->unsubscribeGuest($subscriber);

        return new JsonResponse(['unsubscribed' => true]);
    }

    public function unsubscribe(string $customer): JsonResponse
    {
        $this->signUps->unsubscribeCustomer($customer);

        return new JsonResponse(['unsubscribed' => true]);
    }
}
