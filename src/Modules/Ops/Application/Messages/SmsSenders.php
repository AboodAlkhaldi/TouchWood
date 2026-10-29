<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/**
 * The SMS providers Ops has, by name (owner, 2026-10-29; access.md amendment 87): each SMS country
 * names the one that sends to it, and one provider may serve many countries. Only the providers built
 * here are offered; a new one is a new adapter and a line in its binding, its keys in .env only.
 */
interface SmsSenders
{
    /**
     * @return list<string> the names a country may choose, `log` among them
     */
    public function names(): array;

    /**
     * @throws ProviderFailed when there is no such provider, or it may not run here - `log` on a live
     *                        system
     */
    public function named(string $provider): SmsSender;
}
