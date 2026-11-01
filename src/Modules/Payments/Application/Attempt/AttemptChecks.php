<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Application\Gateway\GatewayAccess;
use Modules\Payments\Application\Gateway\GatewayAccesses;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Application\Gateway\PaymentAnswer;
use Modules\Payments\Domain\Exception\AttemptNotFound;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Domain\Repository\Transactions;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Psr\Log\LoggerInterface;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * Asking the gateway about an attempt (payments.md §1.1 rules 1, 7, 9): its answer is the only truth, and whatever
 * made us ask is believed in nothing. One call at a time per attempt (§1.8); a page gets an answer up to 10 seconds
 * old rather than a new call.
 */
final readonly class AttemptChecks
{
    public const int ANSWER_REUSE_SECONDS = 10;

    /** Longer than three tries of 30 seconds and their waits. */
    private const int CHECK_HOLD_SECONDS = 120;

    public function __construct(
        private Attempts $attempts,
        private Transactions $transactions,
        private AttemptSettlement $settlement,
        private GatewayAccesses $accesses,
        private GatewayClient $client,
        private PlatformApi $platform,
        private ConnectionInterface $db,
        private LoggerInterface $logger,
    ) {}

    public function isDue(string $attemptId): bool
    {
        $next = $this->attempts->find($attemptId)?->nextCheckAt;

        return $next !== null && $next <= CarbonImmutable::now();
    }

    /**
     * A booked check: only when one is due, so a duplicate job does nothing. False when another call holds the attempt,
     * so the job comes back rather than the chain of checks ending here.
     */
    public function due(string $attemptId): bool
    {
        $attempt = $this->attempts->find($attemptId);

        if ($attempt === null || $attempt->nextCheckAt === null || $attempt->nextCheckAt > CarbonImmutable::now()) {
            return true;
        }

        return ! $this->ask($attempt)->busy;
    }

    /**
     * What a page sees: asks only while the attempt is waiting on an answer, and no more than each 10 seconds.
     */
    public function refresh(string $ownerId): PaymentStateDto
    {
        $attempt = $this->attempts->latestOf($ownerId);

        if ($attempt !== null && $attempt->state->isLive() && ! $this->answeredLately($attempt)) {
            $attempt = $this->ask($attempt)->attempt;
        }

        return AttemptViews::page($attempt);
    }

    /**
     * A signed notice names this payment: asks about it, unless it is already written down as it can end. False when
     * nothing was learned, so it is tried again: MyFatoorah has had its 200, and will not send it twice.
     *
     * @param  string|null  $paymentId  from the signed notice; another of this invoice's transactions may be a second success (rule 3)
     */
    public function noticed(Attempt $attempt, ?string $paymentId): bool
    {
        $known = $paymentId === null ? null : $this->transactions->succeeded($attempt->mode, $paymentId);

        return $known === true || $this->ask($attempt, $paymentId)->learned;
    }

    private function answeredLately(Attempt $attempt): bool
    {
        return $attempt->lastCheckedAt !== null
            && $attempt->lastCheckedAt > CarbonImmutable::now()->subSeconds(self::ANSWER_REUSE_SECONDS);
    }

    private function ask(Attempt $attempt, ?string $paymentId = null): AskOutcome
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        if ($attempt->state === AttemptState::Creating) {
            return new AskOutcome($this->prepared($attempt, $now), false, true);
        }

        // Nothing was ever sent, so nothing can have come of it (rule 15).
        if ($attempt->state === AttemptState::Abandoned) {
            return new AskOutcome($attempt, false, true);
        }

        if (! $this->attempts->claimCheck($attempt->id, $now, CarbonImmutable::now()->addSeconds(self::CHECK_HOLD_SECONDS)->toDateTimeImmutable())) {
            return new AskOutcome($attempt, true);
        }

        try {
            $result = $this->question($attempt, $paymentId);

            $settled = $this->db->transaction(function () use ($attempt, $result): Attempt {
                $current = $this->attempts->lock($attempt->id) ?? throw new AttemptNotFound;

                return $this->settlement->settle($current, $result, CarbonImmutable::now()->toDateTimeImmutable());
            });

            return new AskOutcome($settled, false, $result->payment !== null || $result->sessionUnused);
        } finally {
            $this->attempts->releaseCheck($attempt->id);
        }
    }

    /** Prepared and never claimed: nothing was sent, so once its window is over it ends with no call (§1.4 step 4). */
    private function prepared(Attempt $attempt, DateTimeImmutable $now): Attempt
    {
        if ($now < $attempt->windowEndsAt) {
            return $attempt;
        }

        return $this->db->transaction(function () use ($attempt, $now): Attempt {
            $current = $this->attempts->lock($attempt->id) ?? throw new AttemptNotFound;

            return $current->state === AttemptState::Creating
                ? $this->settlement->neverCharged($current, AttemptState::Abandoned, $now)
                : $current;
        });
    }

    private function question(Attempt $attempt, ?string $paymentId): AskResult
    {
        // Whatever goes wrong in asking is "we do not know": the hold limit must still release the try (rule 15).
        try {
            return $this->tried($attempt, $paymentId);
        } catch (Throwable $failure) {
            $this->logger->error('Asking the gateway about an attempt failed.', ['attempt_id' => $attempt->id, 'error' => $failure::class]);

            return new AskResult;
        }
    }

    private function tried(Attempt $attempt, ?string $paymentId): AskResult
    {
        $store = $this->platform->store(StoreId::fromString($attempt->storeId));
        $access = $store === null ? null : $this->accesses->of($store, $attempt);

        if ($store === null || $access === null) {
            return new AskResult;
        }

        try {
            return $this->asked($attempt, $access, $paymentId);
        } catch (GatewayKeyRefused|KeyPermissionMissing) {
            $this->accesses->refused($access);
            // The old key no longer answers: the store's new one may, when it is of the same account (§1.2).
            $current = $this->accesses->current($store, $attempt->mode);

            if ($current === null || $current->keyId === $access->keyId) {
                return new AskResult;
            }

            try {
                return $this->asked($attempt, $current->forAttempt($attempt->id), $paymentId);
            } catch (GatewayKeyRefused|KeyPermissionMissing|GatewayRefused|GatewayUnreachable) {
                return new AskResult;
            }
        } catch (GatewayRefused|GatewayUnreachable) {
            // Not an answer about this payment: never read as a no (rule 4).
            return new AskResult;
        }
    }

    /**
     * @throws GatewayKeyRefused|KeyPermissionMissing|GatewayRefused|GatewayUnreachable
     */
    private function asked(Attempt $attempt, GatewayAccess $access, ?string $paymentId): AskResult
    {
        $payment = $paymentId ?? $attempt->gatewayPaymentId;

        if ($payment !== null) {
            return $this->matched($attempt, $this->client->paymentDetails($access, $payment));
        }

        // No payment known: the charge's answer was lost, so its session says what came of it (rule 4).
        $session = $this->client->sessionDetails($access, $attempt->sessionId);

        // The session only names the payment: what it came to is the payment's own answer (rule 1).
        return $session->payment === null
            ? new AskResult(null, ! $session->isUsed)
            : $this->matched($attempt, $this->client->paymentDetails($access, $session->payment->paymentId));
    }

    /** The invoice and our id must both be this attempt's, or it is applied to nothing (rule 7). */
    private function matched(Attempt $attempt, PaymentAnswer $answer): AskResult
    {
        $invoiceOk = $attempt->invoiceId === null || $attempt->invoiceId === $answer->invoiceId;

        if (! $invoiceOk || $answer->externalIdentifier !== $attempt->id) {
            $this->logger->warning('A gateway answer named another attempt; it was applied to nothing.', ['attempt_id' => $attempt->id]);

            return new AskResult;
        }

        // A success we cannot read the money of is no answer yet: written down, it could never be read again (rule 8).
        if (strtoupper($answer->transactionStatus) === 'SUCCESS'
            && ($answer->displayCurrency === null || $answer->displayAmount === null || ($attempt->mode === PaymentMode::Live && $answer->baseCurrency === null))) {
            $this->logger->warning('A gateway answer named a success without its amount.', ['attempt_id' => $attempt->id]);

            return new AskResult;
        }

        return new AskResult($answer);
    }
}
