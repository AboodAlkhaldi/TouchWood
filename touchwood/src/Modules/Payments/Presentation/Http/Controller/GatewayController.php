<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\RegisterApplePayDomain\RegisterApplePayDomain;
use Modules\Payments\Application\Command\RegisterApplePayDomain\RegisterApplePayDomainHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Command\SwitchMode\SwitchMode;
use Modules\Payments\Application\Command\SwitchMode\SwitchModeHandler;
use Modules\Payments\Application\Gateway\GatewayBanners;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\Query\ViewGateway\ViewGatewayHandler;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Presentation\Http\Resource\GatewayPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/*
| The Gateway page (payments.md §4.2; the owner, 2026-11-01, (a) in all twenty-two): keys, mode, on/off, Apple Pay.
| Every post names its store (`store`, its code) and asks step 8's and 9's handlers, which authorize again. The
| timers are Platform's settings, saved through Platform's own route (Q17). A key and its secret are only ever
| passed on: never logged, flashed or sent back.
*/
final readonly class GatewayController
{
    /** @var list<string> */
    private const array WORDS = ['payments::admin_gateway', 'payments::errors', 'admin'];

    public function __construct(
        private Page $page,
        private GatewayBanners $banners,
    ) {}

    public function index(Request $request, GatewayPages $pages, ViewGatewayHandler $view): Response
    {
        $code = $request->query('store');

        return $this->page->render('Payments/Admin/Gateway', $pages->page(is_string($code) ? $code : null, $view)->toArray(), self::WORDS);
    }

    /** Change Key…: the store's first key sets its gateway up, switched off (§9.9 #2). */
    public function keys(Request $request, StoreChoices $choices, ChangeKeysHandler $handler): RedirectResponse
    {
        return $this->act($request, function () use ($request, $choices, $handler): void {
            $handler->handle(new ChangeKeys(
                self::store($request, $choices)->id, self::text($request, 'mode'), self::text($request, 'key'), self::text($request, 'secret'), self::text($request, 'expires_on'),
            ));
        }, 'payments::admin_gateway.toast.key');
    }

    public function mode(Request $request, StoreChoices $choices, SwitchModeHandler $handler): RedirectResponse
    {
        return $this->act($request, function () use ($request, $choices, $handler): void {
            $handler->handle(new SwitchMode(self::store($request, $choices)->id, self::text($request, 'mode')));
        }, 'payments::admin_gateway.toast.'.($request->input('mode') === 'LIVE' ? 'live' : 'test'));
    }

    public function switch(Request $request, StoreChoices $choices, SwitchGatewayHandler $handler): RedirectResponse
    {
        return $this->act($request, function () use ($request, $choices, $handler): void {
            $handler->handle(new SwitchGateway(self::store($request, $choices)->id, $request->boolean('on')));
        }, 'payments::admin_gateway.toast.'.($request->boolean('on') ? 'on' : 'off'));
    }

    /** Registers the domain this deployment answers on (§1.2), never one typed. */
    public function applePay(Request $request, StoreChoices $choices, GatewayPages $pages, RegisterApplePayDomainHandler $handler): RedirectResponse
    {
        return $this->act($request, function () use ($request, $choices, $handler, $pages): void {
            $handler->handle(new RegisterApplePayDomain(self::store($request, $choices)->id, self::text($request, 'mode'), $pages->domain()));
        }, 'payments::admin_gateway.toast.apple_pay');
    }

    private function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (GatewayRefused $error) {
            // Its own words are for staff's screens (§7): this page is staff's alone.
            $this->banners->forget();

            return back()->withErrors(['form' => __('payments::admin_gateway.refused', ['reason' => $error->reason])]);
        } catch (DomainError $error) {
            // A refused key was marked on the way: the banner says so at once.
            $this->banners->forget();

            return FormErrors::back($request, $error);
        }

        $this->banners->forget();

        return back()->with('status', __($done));
    }

    /** @throws Unauthorized when no store is named, or one the reader doesn't manage */
    private static function store(Request $request, StoreChoices $choices): StoreDto
    {
        $code = trim(self::text($request, 'store'));

        return ($code === '' ? null : $choices->chosen($code, PaymentsPermissions::GATEWAY_MANAGE)) ?? throw new Unauthorized(PaymentsPermissions::GATEWAY_MANAGE);
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }
}
