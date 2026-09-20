<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Support\Str;
use Shared\Application\Actor;

/**
 * Who this web request acts as: a guest until a session says otherwise. Bound scoped, so it lives
 * for one request or one job.
 *
 * A guest's id is made for this request only: keeping it in an encrypted cookie comes with guests
 * and carts (spec §1.7, step 4).
 */
final class RequestActor
{
    private ?Actor $actor = null;

    private ?Actor $guest = null;

    public function set(Actor $actor): void
    {
        $this->actor = $actor;
    }

    public function actor(): ?Actor
    {
        return $this->actor;
    }

    public function guest(): Actor
    {
        return $this->guest ??= Actor::guest(strtolower((string) Str::ulid()));
    }

    public function clear(): void
    {
        $this->actor = null;
    }
}
