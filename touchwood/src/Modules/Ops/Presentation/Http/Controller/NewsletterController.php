<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response as InertiaResponse;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Newsletter\NewsletterSignUps;
use Modules\Ops\Domain\Exception\NewsletterUnavailable;
use Modules\Ops\Domain\Exception\SubscribeInYourAccount;
use Modules\Ops\Presentation\Http\Resource\EmailLinkPageData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\StoreContext;

/*
| The shop's newsletter box and its links (ops.md §1.9, §3: guest / signed link). The box sends JSON and gets
| JSON; a link opens its page to a plain visit and today's JSON to a screen asking for it (§4.15 O11, O13).
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
|   They answer for a switched-off store too (owner, 2026-11-01; shop-routes.php).
*/
final readonly class NewsletterController
{
    public const int SIGN_UPS_AN_HOUR_FOR_AN_EMAIL = 5;

    public const int SIGN_UPS_AN_HOUR_FROM_AN_ADDRESS = 20;

    /** The one page all three emailed links open (ops.md §4.15 O13); its words travel as its data. */
    private const string PAGE = 'Ops/Storefront/EmailLink';

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

        try {
            $this->signUps->subscribe($store, app()->getLocale(), $email);
        } catch (ProviderFailed $failed) {
            // Logged for us, its own answer for the person; it names no address.
            report($failed);

            throw new NewsletterUnavailable;
        }

        return new JsonResponse(['sent' => true], 202);
    }

    /**
     * What the confirmation link opens: the page to a plain visit (§4.15 O13), today's JSON to a screen asking for it.
     * Its store is on - the shop's middleware has seen to it - so the page offers the way to it after confirming.
     */
    public function confirmation(Request $request, string $token, Page $page, StoreContext $stores, PlatformApi $platform): InertiaResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'token' => $token,
                'title' => __('ops::newsletter.confirm.title'),
                'text' => __('ops::newsletter.confirm.text'),
                'action' => __('ops::newsletter.confirm.action'),
                'done' => __('ops::newsletter.confirm.done'),
            ]);
        }

        $store = $platform->store($stores->current()) ?? abort(404);

        return $page->render(self::PAGE, (new EmailLinkPageData(
            __('ops::newsletter.confirm.title'),
            __('ops::newsletter.confirm.text'),
            __('ops::newsletter.confirm.action'),
            __('ops::newsletter.confirm.done'),
            __('ops::newsletter.confirm.failed'),
            '/'.$store->code.'/'.app()->getLocale(),
            __('ops::newsletter.confirm.shop'),
        ))->toArray(), []);
    }

    public function confirm(string $token): JsonResponse
    {
        $this->signUps->confirm($token);

        return new JsonResponse(['confirmed' => true]);
    }

    public function leavePage(Request $request, Page $page): InertiaResponse|JsonResponse
    {
        return self::page($request, $page, 'leave');
    }

    public function unsubscribePage(Request $request, Page $page): InertiaResponse|JsonResponse
    {
        return self::page($request, $page, 'unsubscribe');
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

    /**
     * What the page shows, in the page's language: its title, its line, its one button. Nothing changes. The page to a
     * plain visit (§4.15 O13), with no way on to the store, which may be off; today's JSON to a screen asking for it.
     */
    private static function page(Request $request, Page $page, string $which): InertiaResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'title' => __("ops::unsubscribe.{$which}.title"),
                'text' => __("ops::unsubscribe.{$which}.text"),
                'action' => __("ops::unsubscribe.{$which}.action"),
                'done' => __("ops::unsubscribe.{$which}.done"),
            ]);
        }

        return $page->render(self::PAGE, (new EmailLinkPageData(
            __("ops::unsubscribe.{$which}.title"),
            __("ops::unsubscribe.{$which}.text"),
            __("ops::unsubscribe.{$which}.action"),
            __("ops::unsubscribe.{$which}.done"),
            __("ops::unsubscribe.{$which}.failed"),
        ))->toArray(), []);
    }
}
