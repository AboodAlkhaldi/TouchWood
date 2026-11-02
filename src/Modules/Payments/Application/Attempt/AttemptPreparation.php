<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Payments\Application\PaymentsSettings;
use Modules\Payments\Domain\Exception\AttemptNotFound;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoOnlinePayment;
use Modules\Payments\Domain\Exception\PaymentInProgress;
use Modules\Payments\Domain\Exception\SessionNotUsable;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Domain\Repository\Sessions;
use Modules\Payments\Public\Dto\PayRequestDto;
use Modules\Payments\Public\Enums\PaymentOwnerKind;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * The attempt, written inside Sales's transaction with no call to the gateway (payments.md §1.4 step 3): the
 * session checked (rule 7), its idempotency key made (rule 4), its checks booked (rule 9) - so no try ever exists
 * without an attempt that will be checked. And the one way to end it before anything is sent (rule 15).
 */
final readonly class AttemptPreparation
{
    public function __construct(
        private PlatformApi $platform,
        private Gateways $gateways,
        private Sessions $sessions,
        private Attempts $attempts,
        private AttemptSettlement $settlement,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NoOnlinePayment|SessionNotUsable|PaymentInProgress|InvalidPaymentsInput
     */
    public function prepare(PayRequestDto $request): string
    {
        if (filter_var($request->returnUrl, FILTER_VALIDATE_URL) === false || ! str_starts_with(strtolower($request->returnUrl), 'http')) {
            throw new InvalidPaymentsInput('return_url', 'an address on this site');
        }

        return $this->db->transaction(function () use ($request): string {
            $store = $this->platform->store($request->storeId) ?? throw new NoOnlinePayment;
            $gateway = $this->gateways->of($store->id);
            $key = $gateway === null ? null : $this->gateways->currentKey($store->id, $gateway->mode);

            if ($gateway === null || ! $gateway->active || $key === null || $key->rejected) {
                throw new NoOnlinePayment;
            }

            // A double click on the same session gets the same attempt; another session is another payment.
            $open = $this->attempts->openOf($request->ownerId);

            if ($open !== null) {
                return $open->sessionId === $request->sessionId ? $open->id : throw new PaymentInProgress;
            }

            $now = CarbonImmutable::now();
            $session = $this->sessions->lock($gateway->mode, $request->sessionId);
            $sessionOwner = $request->ownerKind === PaymentOwnerKind::Try ? $request->quoteId : $request->ownerId;

            if ($session === null
                || $session->storeId !== $store->id
                || $session->ownerId !== $sessionOwner
                || $session->buyerId !== $request->buyerId
                || $session->amountMinor !== $request->amount->minorUnits
                || $session->currencyCode !== $request->amount->currencyCode
                || $session->expiresAt <= $now
                || $session->usedBy !== null) {
                throw new SessionNotUsable;
            }

            $window = $this->platform->setting(PaymentsSettings::WINDOW_MINUTES, $request->storeId)->int();
            $wait = $this->platform->setting(PaymentsSettings::WAIT_MINUTES, $request->storeId)->int();
            $hold = $this->platform->setting(PaymentsSettings::HOLD_MINUTES, $request->storeId)->int();
            $id = strtolower((string) Str::ulid());

            $attempt = new Attempt(
                $id,
                $store->id,
                $request->ownerKind,
                $request->ownerId,
                $request->orderId,
                $request->buyerId,
                $session->id,
                $key->id,
                $gateway->mode,
                $session->currencyCode,
                $session->amountMinor,
                AttemptState::Creating,
                (string) Str::uuid(),
                $request->locale,
                $request->returnUrl,
                $now->addMinutes($window)->toDateTimeImmutable(),
                $now->addMinutes($window + $wait)->toDateTimeImmutable(),
                $now->addMinutes($hold)->toDateTimeImmutable(),
                $now->toDateTimeImmutable(),
            );

            try {
                $this->attempts->add($attempt, $request->customerName, $request->customerEmail, $request->customerPhone);
            } catch (UniqueConstraintViolationException) {
                // Two presses at the same instant: rule 3's index let one through.
                throw new PaymentInProgress;
            }

            $this->sessions->markUsed($gateway->mode, $session->id, $id);
            $this->settlement->booked($attempt, $now->toDateTimeImmutable());

            return $id;
        });
    }

    /**
     * Ends a prepared attempt that was never charged; false once the charge is claimed (rule 15). Sales asks when
     * checkout is opened again, and releases the try itself, so no event goes out.
     *
     * @throws AttemptNotFound
     */
    public function abandon(string $attemptId): bool
    {
        return $this->db->transaction(function () use ($attemptId): bool {
            $attempt = $this->attempts->lock($attemptId) ?? throw new AttemptNotFound;

            if ($attempt->state === AttemptState::Abandoned) {
                return true;
            }

            if ($attempt->state !== AttemptState::Creating) {
                return false;
            }

            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $this->attempts->save($attempt->with(['state' => AttemptState::Abandoned, 'nothingCharged' => true, 'nextCheckAt' => null]), $now);

            return true;
        });
    }
}
