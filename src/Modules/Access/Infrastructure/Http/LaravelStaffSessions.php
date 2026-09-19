<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Session\PendingSignIn;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Shared\Application\Actor;

/**
 * The admin session, in Laravel's session with the admin panel's own cookie (UseAdminSession).
 * Every request checks it against the staff member's cached permissions, which carry their status
 * and session version: a warm request reads only the cache table (spec §5.4). A disabled account,
 * a changed password, 30 minutes idle or 12 hours after signing in end it at once (spec §1.8).
 */
final readonly class LaravelStaffSessions implements StaffSessions
{
    private const string SIGNED_IN = 'access.staff';

    private const string PENDING = 'access.staff_pending';

    /** How long the code step waits after the right password. */
    private const int PENDING_MINUTES = 15;

    public function __construct(
        private Container $app,
        private RequestActor $actor,
        private GrantsReader $grants,
        private StaffSecuritySettings $settings,
    ) {}

    public function signedIn(): ?string
    {
        $session = $this->session();
        $data = $session->get(self::SIGNED_IN);

        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_int($data['version'] ?? null)
            || ! is_int($data['signed_in_at'] ?? null) || ! is_int($data['seen_at'] ?? null)) {
            return null;
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $idle = $now - $data['seen_at'] > $this->settings->sessionIdleMinutes() * 60;
        $expired = $now - $data['signed_in_at'] > $this->settings->sessionMaxHours() * 3600;
        $grants = $idle || $expired ? null : $this->grants->forStaff($data['id']);

        if ($grants === null || ! $grants->isActive() || $grants->sessionVersion !== $data['version']) {
            $this->end();

            return null;
        }

        $session->put(self::SIGNED_IN, [...$data, 'seen_at' => $now]);
        $this->actor->set(Actor::staff($data['id']));

        return $data['id'];
    }

    public function beginSignIn(string $staffId, int $sessionVersion, bool $needsPhone): void
    {
        $this->session()->put(self::PENDING, [
            'id' => $staffId,
            'version' => $sessionVersion,
            'needs_phone' => $needsPhone,
            'at' => CarbonImmutable::now()->getTimestamp(),
        ]);
    }

    public function pendingSignIn(): ?PendingSignIn
    {
        $data = $this->session()->get(self::PENDING);

        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_int($data['version'] ?? null)
            || ! is_int($data['at'] ?? null)) {
            return null;
        }

        if (CarbonImmutable::now()->getTimestamp() - $data['at'] > self::PENDING_MINUTES * 60) {
            $this->session()->forget(self::PENDING);

            return null;
        }

        return new PendingSignIn($data['id'], $data['version'], ($data['needs_phone'] ?? false) === true);
    }

    public function start(string $staffId, int $sessionVersion): void
    {
        $session = $this->session();
        $now = CarbonImmutable::now()->getTimestamp();

        $session->forget(self::PENDING);
        // A new id at every sign-in, and the old one destroyed (spec §1.8).
        $session->migrate(true);
        $session->regenerateToken();
        $session->put(self::SIGNED_IN, ['id' => $staffId, 'version' => $sessionVersion, 'signed_in_at' => $now, 'seen_at' => $now]);

        $this->actor->set(Actor::staff($staffId));
    }

    public function keep(int $sessionVersion): void
    {
        $session = $this->session();
        $data = $session->get(self::SIGNED_IN);

        if (is_array($data)) {
            $session->put(self::SIGNED_IN, [...$data, 'version' => $sessionVersion]);
        }
    }

    public function end(): void
    {
        $this->session()->invalidate();
        $this->actor->set($this->actor->guest());
    }

    /**
     * The request's own session — the admin one on /admin — or, outside a request, the default.
     */
    private function session(): Session
    {
        $request = $this->app->make(Request::class);

        return $request->hasSession() ? $request->session() : $this->app->make('session.store');
    }
}
