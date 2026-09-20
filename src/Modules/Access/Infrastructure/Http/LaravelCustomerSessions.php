<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Modules\Access\Application\Session\CustomerSessions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Public\Enums\CustomerStatus;
use Shared\Application\Actor;

/**
 * The storefront session, in the site's own cookie — the admin panel keeps its own (spec §1.8).
 * Every request checks it against the account: a blocked customer and a changed password end it at
 * once. A signed-in request reads one row, the customer's, by its primary key.
 *
 * Without "remember me" the session ends after the store's idle minutes; with it, it lasts the
 * store's remembered days however quiet the customer is.
 */
final readonly class LaravelCustomerSessions implements CustomerSessions
{
    private const string SIGNED_IN = 'access.customer';

    public function __construct(
        private Container $app,
        private RequestActor $actor,
        private CustomerRepository $customers,
        private CustomerSecuritySettings $settings,
    ) {}

    public function signedIn(): ?string
    {
        $session = $this->session();

        if ($session === null) {
            return null;
        }

        $data = $session->get(self::SIGNED_IN);

        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_int($data['version'] ?? null)
            || ! is_int($data['signed_in_at'] ?? null) || ! is_int($data['seen_at'] ?? null)) {
            return null;
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $remembered = ($data['remember'] ?? false) === true;
        $over = $remembered
            ? $now - $data['signed_in_at'] > $this->settings->rememberDays() * 86400
            : $now - $data['seen_at'] > $this->settings->sessionIdleMinutes() * 60;
        $customer = $over ? null : $this->customers->find($data['id']);

        if ($customer === null || $customer->status() !== CustomerStatus::Active || $customer->sessionVersion() !== $data['version']) {
            $this->end();

            return null;
        }

        $session->put(self::SIGNED_IN, [...$data, 'seen_at' => $now]);
        $this->actor->set(Actor::customer($data['id']));

        return $data['id'];
    }

    public function start(string $customerId, int $sessionVersion, bool $remember): void
    {
        $session = $this->session();

        if ($session === null) {
            return;
        }

        $now = CarbonImmutable::now()->getTimestamp();

        // A new id at every sign-in, and the old one destroyed (spec §1.8).
        $session->migrate(true);
        $session->regenerateToken();
        $session->put(self::SIGNED_IN, [
            'id' => $customerId,
            'version' => $sessionVersion,
            'signed_in_at' => $now,
            'seen_at' => $now,
            'remember' => $remember,
        ]);

        $this->actor->set(Actor::customer($customerId));
    }

    public function keep(int $sessionVersion): void
    {
        $session = $this->session();
        $data = $session?->get(self::SIGNED_IN);

        if ($session !== null && is_array($data)) {
            $session->put(self::SIGNED_IN, [...$data, 'version' => $sessionVersion]);
        }
    }

    public function end(): void
    {
        $this->session()?->invalidate();
        $this->actor->set($this->actor->guest());
    }

    /**
     * The request's own session — and only that. Outside a request there is no browser to sign in:
     * the session store is one object for the whole application, so writing to it there would leak
     * into the next request (found while building step 4b).
     */
    private function session(): ?Session
    {
        $request = $this->app->make(Request::class);

        return $request->hasSession() ? $request->session() : null;
    }
}
