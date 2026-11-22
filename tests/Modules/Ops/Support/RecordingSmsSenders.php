<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use Modules\Ops\Application\Messages\CodePurpose;
use Modules\Ops\Application\Messages\CodeSender;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Application\Messages\WhatsAppAlert;

/**
 * Three made-up providers of codes, `log`, `whatsapp` and `second`, that keep what they would send - which provider, to
 * which number, what text - so a test can see which one a country's phones go through. A code is recorded in the words
 * the `log` driver writes it in (`ops::security.phone_code` or `sign_in_code`), with its language, purpose and code
 * kept in `details`; an alert in its own words, with the alert's kind. `failing()` makes them refuse instead.
 */
final class RecordingSmsSenders implements SmsSenders
{
    /** @var list<array{provider: string, phone: string, message: string}> */
    public array $sent = [];

    /** @var list<array{locale: string, purpose: ?CodePurpose, code: ?string, alert: ?WhatsAppAlert}> what each of `sent` was, in the same order */
    public array $details = [];

    private bool $fails = false;

    public static function install(): self
    {
        $senders = new self;
        app()->instance(SmsSenders::class, $senders);

        return $senders;
    }

    public function failing(): self
    {
        $this->fails = true;

        return $this;
    }

    public function names(): array
    {
        return ['log', 'whatsapp', 'second'];
    }

    public function named(string $provider): CodeSender
    {
        if (! in_array($provider, $this->names(), true)) {
            throw new ProviderFailed("No SMS provider is called \"{$provider}\".");
        }

        return new readonly class($this, $provider) implements CodeSender
        {
            public function __construct(
                private RecordingSmsSenders $senders,
                private string $provider,
            ) {}

            public function sendCode(string $phone, string $locale, CodePurpose $purpose, string $code): string
            {
                $key = $purpose === CodePurpose::SignIn ? 'sign_in_code' : 'phone_code';

                return $this->senders->record($this->provider, $phone, (string) trans("ops::security.{$key}", ['code' => $code], $locale), $locale, $purpose, $code, null);
            }

            public function sendAlert(string $phone, string $locale, WhatsAppAlert $alert, string $text): string
            {
                return $this->senders->record($this->provider, $phone, $text, $locale, null, null, $alert);
            }
        };
    }

    public function record(string $provider, string $phone, string $message, string $locale, ?CodePurpose $purpose, ?string $code, ?WhatsAppAlert $alert): string
    {
        if ($this->fails) {
            throw new ProviderFailed("{$provider} refused the SMS.");
        }

        $this->sent[] = ['provider' => $provider, 'phone' => $phone, 'message' => $message];
        $this->details[] = ['locale' => $locale, 'purpose' => $purpose, 'code' => $code, 'alert' => $alert];

        return 'sms-'.count($this->sent);
    }
}
