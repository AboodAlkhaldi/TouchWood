<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Closure;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Messages\SmsSender;
use Modules\Ops\Application\Messages\SmsSenders;

/**
 * The SMS providers built so far, by name (access.md amendment 87): only `log` until the owner names
 * a real one. `log` writes every code to the log, so it never sends on a live system: refused there
 * when an SMS would go through it, and the person's request fails rather than nothing being sent.
 */
final readonly class BuiltSmsSenders implements SmsSenders
{
    /**
     * @param  array<string, Closure(): SmsSender>  $senders  each provider's adapter, made when first used
     */
    public function __construct(
        private array $senders,
        private bool $production,
    ) {}

    public function names(): array
    {
        return array_keys($this->senders);
    }

    public function named(string $provider): SmsSender
    {
        $make = $this->senders[$provider] ?? throw new ProviderFailed("No SMS provider is called \"{$provider}\".");

        if ($provider === 'log' && $this->production) {
            throw new ProviderFailed('The "log" SMS provider writes messages to the log and never runs in production: give the country a real provider.');
        }

        return $make();
    }
}
