<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Connection;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\Exception\CodeRequestTooSoon;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Model\CustomerPhoneCode;
use Modules\Access\Domain\Repository\CustomerTokenRepository;
use Modules\Access\Domain\ValueObject\CustomerPhoneCodePurpose;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Contracts\SecurityMessages;

/**
 * Verifying a customer's phone by SMS code (spec §1.3, §4.2), with that store's numbers: a code of
 * the set length, valid for the set minutes; a new one no sooner than the resend interval, and at
 * most so many an hour per number; after the set wrong tries the code is dead. A new request
 * replaces the previous code, so a customer has one live code at a time.
 */
final readonly class CustomerPhoneVerification
{
    public function __construct(
        private CustomerTokenRepository $tokens,
        private Codes $codes,
        private CustomerSecuritySettings $settings,
        private SecurityMessages $messages,
        private RateLimiter $limiter,
        private Connection $db,
    ) {}

    /**
     * Call inside the transaction of the change: the SMS leaves only once it commits.
     *
     * @throws CodeRequestTooSoon
     */
    public function send(string $customerId, PhoneNumber $phone, CustomerPhoneCodePurpose $purpose, Language $language, DateTimeImmutable $now): void
    {
        $previous = $this->tokens->phoneCode($customerId);
        $wait = $previous === null ? 0 : $previous->sentAt->getTimestamp() + $this->settings->codeResendSeconds() - $now->getTimestamp();

        if ($wait > 0) {
            throw new CodeRequestTooSoon($wait);
        }

        // Per number, not per account: nobody can make one phone ring by registering many accounts.
        $hourly = 'access:customer-sms:'.hash('sha256', $phone->value);

        if ($this->limiter->tooManyAttempts($hourly, $this->settings->codesPerHour())) {
            throw new CodeRequestTooSoon($this->limiter->availableIn($hourly));
        }

        $this->limiter->hit($hourly, 3600);

        $code = $this->codes->generate($this->settings->codeLength());
        $this->tokens->putPhoneCode(new CustomerPhoneCode(
            $customerId,
            $purpose,
            $phone,
            $this->codes->hash($customerId, $code),
            0,
            $now->modify('+'.$this->settings->codeMinutes().' minutes'),
            $now,
        ));

        // The code never enters an event or a queued job (spec §2.3).
        $this->db->afterCommit(fn () => $this->messages->phoneCode($phone->value, $language->value, $code));
    }

    /**
     * The verified phone, with the code used up; or the failure, with a wrong try already counted.
     * The failure is returned, not thrown, so the caller can commit the count before throwing it:
     * a rolled-back count would let a code be guessed without limit.
     */
    public function check(string $customerId, string $code, DateTimeImmutable $now): PhoneNumber|InvalidCode
    {
        $stored = $this->tokens->phoneCode($customerId);

        if ($stored === null || $stored->isExpired($now) || $stored->attempts >= $this->settings->codeAttempts()) {
            return new InvalidCode(requestNewCode: true);
        }

        if (! $this->codes->matches($customerId, $code, $stored->codeHash)) {
            $this->tokens->countFailedAttempt($customerId);

            return new InvalidCode(requestNewCode: $stored->attempts + 1 >= $this->settings->codeAttempts());
        }

        $this->tokens->deletePhoneCode($customerId);

        return $stored->phone;
    }
}
