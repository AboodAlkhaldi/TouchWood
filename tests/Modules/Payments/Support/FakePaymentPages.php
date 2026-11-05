<?php

declare(strict_types=1);

namespace Tests\Modules\Payments\Support;

use Illuminate\Support\Facades\Route;
use Modules\Payments\Application\Gateway\GatewayAddresses;
use Modules\Payments\Public\Enums\PaymentMode;

/**
 * MyFatoorah's form and bank page, for a real browser: their own need a key and a staging site. The real Payments runs
 * behind them: only the form's script address is ours here, and the bank's address comes from FakeMyFatoorah::$framePath.
 * The bank's page says `window.bankReady` once it is listening, and answers a posted 'finish' as MyFatoorah's does.
 */
final class FakePaymentPages
{
    public const string BANK = '/__fake-mf/bank';

    /** Waits for the bank's page, whose element comes before the page inside it: a message sent sooner reaches nobody. */
    public const string BANK_READY = "document.querySelector('[data-test=\"payment-frame\"]')?.contentWindow?.bankReady === true";

    public const string FINISH = "document.querySelector('[data-test=\"payment-frame\"]').contentWindow.postMessage('finish', '*')";

    /** Before anything is seeded, so nothing resolves the real addresses first. */
    public static function install(): void
    {
        app()->instance(GatewayAddresses::class, new class(app(GatewayAddresses::class)) implements GatewayAddresses
        {
            public function __construct(private GatewayAddresses $real) {}

            public function offered(string $countryCode): bool
            {
                return $this->real->offered($countryCode);
            }

            public function api(string $countryCode, PaymentMode $mode): string
            {
                return $this->real->api($countryCode, $mode);
            }

            public function script(string $countryCode, PaymentMode $mode): string
            {
                return '/__fake-mf/session.js';
            }
        });

        config(['testing.payment_script_down' => false]);
        Route::get('__fake-mf/session.js', static fn () => config('testing.payment_script_down') === true ? response('', 503) : response(<<<'JS'
            window.myfatoorah = {
                init(config) {
                    window.fakeForm = config;
                    document.getElementById(config.containerId).innerHTML = '<div data-test="fake-form">The card form</div>';
                },
                validateCardInputs() { return Promise.resolve(); },
                submitCardPayment() { window.fakeForm.callback({ isSuccess: true, paymentType: 'CARD', sessionId: window.fakeForm.sessionId, paymentCompleted: false }); },
            };
            JS, 200, ['Content-Type' => 'text/javascript']));
        Route::get('__fake-mf/bank', static fn () => response(<<<'HTML'
            <!doctype html><title>Bank</title><p>Enter your code</p>
            <script>window.addEventListener('message', (event) => { if (event.data === 'finish') { window.parent.postMessage(JSON.stringify({ sender: 'MF-3DSecure', url: 'x' }), '*'); } }); window.bankReady = true;</script>
            HTML));
    }
}
