<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\SmsSender;
use Modules\Ops\Application\Messages\SmsSenders;

/**
 * Two made-up SMS providers, `log` and `second`, that keep what they would send - which provider, to
 * which number, what text - so a test can see which one a country's phones go through. `failing()`
 * makes them refuse instead.
 */
final class RecordingSmsSenders implements SmsSenders
{
    /** @var list<array{provider: string, phone: string, message: string}> */
    public array $sent = [];

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
        return ['log', 'second'];
    }

    public function named(string $provider): SmsSender
    {
        if (! in_array($provider, $this->names(), true)) {
            throw new ProviderFailed("No SMS provider is called \"{$provider}\".");
        }

        return new readonly class($this, $provider) implements SmsSender
        {
            public function __construct(
                private RecordingSmsSenders $senders,
                private string $provider,
            ) {}

            public function send(string $phone, string $message): string
            {
                return $this->senders->record($this->provider, $phone, $message);
            }
        };
    }

    public function record(string $provider, string $phone, string $message): string
    {
        if ($this->fails) {
            throw new ProviderFailed("{$provider} refused the SMS.");
        }

        $this->sent[] = ['provider' => $provider, 'phone' => $phone, 'message' => $message];

        return 'sms-'.count($this->sent);
    }
}
