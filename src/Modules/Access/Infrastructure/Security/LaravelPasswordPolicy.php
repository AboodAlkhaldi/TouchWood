<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Security;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Domain\Exception\PasswordTooWeak;

/**
 * Laravel's own pieces: the verifier behind `Password::uncompromised()` — `LoggedBreachList`, which
 * logs an outage and treats the password as not found, so an outage never blocks anyone — and the
 * configured hasher.
 */
final readonly class LaravelPasswordPolicy implements PasswordPolicy
{
    public function __construct(
        private UncompromisedVerifier $breaches,
        private Hasher $hasher,
    ) {}

    public function hashNew(string $password, int $minLength): string
    {
        if (mb_strlen($password) < $minLength) {
            throw new PasswordTooWeak(PasswordTooWeak::TOO_SHORT, $minLength);
        }

        if (! $this->breaches->verify(['value' => $password, 'threshold' => 0])) {
            throw new PasswordTooWeak(PasswordTooWeak::LEAKED, $minLength);
        }

        return $this->hasher->make($password);
    }

    public function matches(string $password, ?string $hash): bool
    {
        if ($hash === null) {
            // As long as a real check takes, so the answer's timing tells nobody the email is unknown.
            $this->hasher->make($password);

            return false;
        }

        return $this->hasher->check($password, $hash);
    }
}
