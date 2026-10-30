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
| - newsletter/leave/{subscriber}: a guest's link from the confirmation email (§3); unsubscribe/{customer}:
|   a marketing email's link (§1.3). Both signed on their path, no sign-in. GET is the page with its one
|   "Unsubscribe" button (owner, 2026-10-30) and changes nothing; POST, with the same signature, takes
|   the guest off or withdraws the consent at once - and is the mail client's one-click POST (RFC 8058).
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

    public function leavePage(): JsonResponse
    {
        return self::page('leave');
    }

    public function unsubscribePage(): JsonResponse
    {
        return self::page('unsubscribe');
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

    /** What the page shows, in the page's language: its title, its line, its one button. Nothing changes. */
    private static function page(string $which): JsonResponse
    {
        return new JsonResponse([
            'title' => __("ops::unsubscribe.{$which}.title"),
            'text' => __("ops::unsubscribe.{$which}.text"),
            'action' => __("ops::unsubscribe.{$which}.action"),
            'done' => __("ops::unsubscribe.{$which}.done"),
        ]);
    }
}
