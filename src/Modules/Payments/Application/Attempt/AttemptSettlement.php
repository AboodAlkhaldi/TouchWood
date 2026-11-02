<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Payments\Application\Gateway\PaymentAnswer;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Payments\Domain\Model\TransactionFacts;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Domain\Repository\Transactions;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Events\PaymentFailed;
use Modules\Payments\Public\Events\PaymentNotAccepted;
use Modules\Payments\Public\Events\PaymentNotTaken;
use Modules\Payments\Public\Events\PaymentSucceeded;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\MoneyException;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * What an answer does to an attempt (payments.md §1.1 rules 3, 5, 6, 8, 15, §4). Always under the attempt's row
 * lock, inside the caller's transaction: its events and its row commit together.
 */
final readonly class AttemptSettlement
{
    /** The transaction's words for a payment that did not go through. */
    private const array DECLINED = ['FAILED', 'CANCELED', 'CANCELLED', 'EXPIRED'];

    public function __construct(
        private Attempts $attempts,
        private Transactions $transactions,
        private Outbox $outbox,
        private PaymentJobs $jobs,
        private PlatformApi $platform,
    ) {}

    /** A transaction that will not change: it went through, or it was declined. */
    public static function isFinal(PaymentAnswer $answer): bool
    {
        $status = strtoupper($answer->transactionStatus);

        return $status === 'SUCCESS' || in_array($status, self::DECLINED, true);
    }

    public function settle(Attempt $attempt, AskResult $result, DateTimeImmutable $now): Attempt
    {
        $attempt = $attempt->with(['lastCheckedAt' => $now]);

        if ($result->payment !== null) {
            $attempt = $this->answered($attempt, $result->payment, $now);
        } elseif ($result->sessionUnused) {
            $attempt = $this->unused($attempt, $now);
        } elseif ($attempt->state === AttemptState::Charging || $attempt->state === AttemptState::Open) {
            $attempt = $attempt->with(['state' => AttemptState::Unknown]);
        }

        $hadAnswer = $result->payment !== null || $result->sessionUnused;

        // Our own clock decides (rule 15, §9.8): MyFatoorah never ends an unfinished payment by itself.
        if ($attempt->state->isLive() && $now >= $attempt->holdEndsAt) {
            $attempt = $this->notTaken($attempt, false, $now);
        }

        // Still no word 20 minutes after the charge (§1.7), about a payment still open to question.
        if (! $hadAnswer && ($attempt->state->isLive() || $attempt->state === AttemptState::Expired) && $attempt->chargeClaimedAt !== null && $now >= CarbonImmutable::instance($attempt->chargeClaimedAt)->addMinutes(CheckSchedule::ATTENTION_MINUTES)) {
            $attempt = $attempt->with(['needsAttention' => true]);
        }

        return $this->booked($attempt, $now);
    }

    /** Ends an attempt nothing of which reached the gateway (rule 15). */
    public function neverCharged(Attempt $attempt, AttemptState $state, DateTimeImmutable $now, ?string $reason = null): Attempt
    {
        $this->outbox->record(new PaymentNotTaken((string) Str::uuid(), $attempt->id, $attempt->ownerId, $attempt->orderId, $now));

        return $this->booked($attempt->with(['state' => $state, 'nothingCharged' => true, 'refusedReason' => $reason]), $now);
    }

    /** Writes the attempt with its next check, and books it. Nothing more to do: nothing is booked. */
    public function booked(Attempt $attempt, DateTimeImmutable $now): Attempt
    {
        $booked = $attempt->nextCheckAt;
        $next = CheckSchedule::next($attempt, $now);

        // The last check passed with no final answer: staff ask MyFatoorah themselves (gap 10.4).
        if ($next === null && $attempt->state === AttemptState::Expired && ! $attempt->nothingCharged) {
            $attempt = $attempt->with(['needsAttention' => true]);
        }

        $attempt = $attempt->with(['nextCheckAt' => $next]);
        $this->attempts->save($attempt, $now);

        // Already booked for that moment: a page asking every 10 seconds adds no jobs.
        if ($next !== null && $next != $booked) {
            $this->jobs->checkAttempt($attempt->id, $next);
        }

        return $attempt;
    }

    private function unused(Attempt $attempt, DateTimeImmutable $now): Attempt
    {
        // Released at the hold limit with no word, and now the session says it was never charged: nothing to look for.
        if ($attempt->state === AttemptState::Expired && $attempt->invoiceId === null) {
            return $attempt->with(['nothingCharged' => true]);
        }

        // A charge that crashed or lost its answer, and the session says it never arrived. A request still on
        // its way has until the wait's end to land; after that the try goes back with "no money was taken".
        if ($attempt->state !== AttemptState::Charging && $attempt->state !== AttemptState::Unknown) {
            return $attempt;
        }

        return $now >= $attempt->waitEndsAt ? $this->notTaken($attempt, true, $now) : $attempt->with(['state' => AttemptState::Unknown]);
    }

    private function notTaken(Attempt $attempt, bool $nothingCharged, DateTimeImmutable $now): Attempt
    {
        $this->outbox->record(new PaymentNotTaken((string) Str::uuid(), $attempt->id, $attempt->ownerId, $attempt->orderId, $now));

        return $attempt->with(['state' => AttemptState::Expired, 'nothingCharged' => $nothingCharged]);
    }

    private function answered(Attempt $attempt, PaymentAnswer $answer, DateTimeImmutable $now): Attempt
    {
        $attempt = $attempt->with([
            'invoiceId' => $attempt->invoiceId ?? $answer->invoiceId,
            'gatewayPaymentId' => $attempt->gatewayPaymentId ?? $answer->paymentId,
        ]);
        $status = strtoupper($answer->transactionStatus);

        // Released as never charged, and here is its payment: the looking goes back on, and staff look (rule 15).
        if ($attempt->nothingCharged && $status !== 'SUCCESS') {
            $attempt = $attempt->with(['nothingCharged' => false, 'needsAttention' => true]);
        }

        if ($status === 'SUCCESS') {
            return $this->succeeded($attempt, $answer, $now);
        }

        // Asked about one transaction while the invoice says another paid: staff look, and its notice settles it.
        $otherPaid = strtoupper($answer->invoiceStatus) === 'PAID' && $attempt->succeededAt === null;

        if ($otherPaid) {
            $attempt = $attempt->with(['needsAttention' => true]);
        }

        if (in_array($status, self::DECLINED, true)) {
            return $this->declined($attempt, $answer, $otherPaid, $now);
        }

        return $attempt->state === AttemptState::Charging || $attempt->state === AttemptState::Unknown
            ? $attempt->with(['state' => AttemptState::Open])
            : $attempt;
    }

    private function succeeded(Attempt $attempt, PaymentAnswer $answer, DateTimeImmutable $now): Attempt
    {
        $known = $this->transactions->succeeded($attempt->mode, $answer->paymentId);

        if ($known === true) {
            return $attempt;
        }

        $first = $attempt->succeededAt === null;
        $late = $first && ! $attempt->state->isLive();
        $paid = $this->paid($attempt, $answer);
        // What the customer was shown, in both modes; in live the account's own currency too (rule 8, gaps 8.1, 9.1).
        $currencyOk = $answer->displayCurrency === $attempt->currencyCode
            && ($attempt->mode === PaymentMode::Test || $answer->baseCurrency === $attempt->currencyCode);
        $amountOk = $paid !== null && $paid === $attempt->amountMinor;
        $paidAt = self::moment($answer->transactionDate);

        $id = $this->transactions->record($attempt, new TransactionFacts(
            $attempt->id,
            $answer->paymentId,
            $answer->transactionStatus,
            true,
            self::method($answer->method),
            $answer->cardBrand,
            $paid,
            $answer->displayAmount,
            $answer->displayCurrency,
            $answer->baseCurrency,
            $paidAt,
            null,
            null,
            ! $currencyOk || ! $amountOk,
            ! $first,
            $currencyOk,
        ));

        if ($currencyOk) {
            $this->outbox->record(new PaymentSucceeded((string) Str::uuid(), $id, $attempt->orderId, $attempt->storeId, $now));
        } elseif ($first) {
            $this->outbox->record(new PaymentNotAccepted((string) Str::uuid(), $attempt->id, $attempt->ownerId, $attempt->orderId, $attempt->storeId, $now));
        }

        return $attempt->with([
            'state' => AttemptState::Succeeded,
            'currencyRefused' => $attempt->currencyRefused || ($first && ! $currencyOk),
            'transactionId' => $first ? $id : $attempt->transactionId,
            'gatewayPaymentId' => $first ? $answer->paymentId : $attempt->gatewayPaymentId,
            'succeededAt' => $attempt->succeededAt ?? $paidAt ?? $now,
            'paidMinor' => $first ? $paid : $attempt->paidMinor,
            'mismatch' => $attempt->mismatch || ! $currencyOk || ! $amountOk,
            'paidTwice' => $attempt->paidTwice || ! $first,
            'needsAttention' => $attempt->needsAttention || ! $currencyOk || ! $amountOk || ! $first || $late,
            'nothingCharged' => false,
        ]);
    }

    private function declined(Attempt $attempt, PaymentAnswer $answer, bool $otherPaid, DateTimeImmutable $now): Attempt
    {
        if ($this->transactions->succeeded($attempt->mode, $answer->paymentId) !== null) {
            return $attempt;
        }

        $id = $this->transactions->record($attempt, new TransactionFacts(
            $attempt->id,
            $answer->paymentId,
            $answer->transactionStatus,
            false,
            self::method($answer->method),
            $answer->cardBrand,
            null,
            $answer->displayAmount,
            $answer->displayCurrency,
            $answer->baseCurrency,
            self::moment($answer->transactionDate),
            $answer->errorCode,
            $answer->errorMessage,
            false,
            false,
            $attempt->state->isLive(),
        ));

        // The attempt follows its own payment: another's decline, or a decline on an invoice someone paid, ends nothing.
        if ($otherPaid || $answer->paymentId !== $attempt->gatewayPaymentId) {
            return $attempt;
        }

        // Released earlier: this is the final answer it was still being asked for, and Sales needs no second word.
        if ($attempt->state === AttemptState::Expired) {
            return $attempt->with(['state' => AttemptState::Failed]);
        }

        // Success is final (rule 5).
        if (! $attempt->state->isLive()) {
            return $attempt;
        }

        $this->outbox->record(new PaymentFailed((string) Str::uuid(), $id, $attempt->orderId, $now));

        return $attempt->with(['state' => AttemptState::Failed]);
    }

    /** What was really paid, exactly, in the currency shown; null when it cannot be read so (rule 8). */
    private function paid(Attempt $attempt, PaymentAnswer $answer): ?int
    {
        $store = $this->platform->store(StoreId::fromString($attempt->storeId));

        if ($store === null || $answer->displayAmount === null || $answer->displayCurrency !== $attempt->currencyCode) {
            return null;
        }

        try {
            return Money::fromDecimal($answer->displayAmount, $attempt->currencyCode, $store->currencyExponent)->minorUnits;
        } catch (MoneyException) {
            return null;
        }
    }

    /** Our own plain names (gap 9.2): a Mada card comes back as "VISA/MASTER", so nothing names it from the card. */
    private static function method(?string $gateway): string
    {
        $word = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $gateway));

        return match (true) {
            str_contains($word, 'APPLE') => 'Apple Pay',
            str_contains($word, 'STC') => 'STC Pay',
            $word === '' => 'Unknown',
            default => 'Card',
        };
    }

    private static function moment(?string $text): ?DateTimeImmutable
    {
        if ($text === null) {
            return null;
        }

        $parsed = date_create_immutable($text);

        return $parsed === false ? null : $parsed;
    }
}
