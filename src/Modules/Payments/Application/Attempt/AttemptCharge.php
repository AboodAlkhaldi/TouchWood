<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Application\Gateway\ChargeAnswer;
use Modules\Payments\Application\Gateway\ChargeRequest;
use Modules\Payments\Application\Gateway\GatewayAccesses;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Application\Gateway\OwnAddresses;
use Modules\Payments\Domain\Exception\AttemptNotFound;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Public\Dto\PaymentStartDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * The charge, after Sales's commit (payments.md §1.1 rule 4, §1.4 step 4). **Claimed first**: under the row's lock
 * the attempt becomes `CHARGING`, committed, and only then is the call made, so a second `charge` makes no call,
 * and nothing can end it as "never charged" (rule 15). Safe to call again.
 */
final readonly class AttemptCharge
{
    public function __construct(
        private Attempts $attempts,
        private AttemptSettlement $settlement,
        private GatewayAccesses $accesses,
        private GatewayClient $client,
        private OwnAddresses $own,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws AttemptNotFound
     */
    public function charge(string $attemptId): PaymentStartDto
    {
        $claimed = $this->db->transaction(function () use ($attemptId): array {
            $attempt = $this->attempts->lock($attemptId) ?? throw new AttemptNotFound;

            if ($attempt->state !== AttemptState::Creating) {
                return [$attempt, false];
            }

            $now = CarbonImmutable::now()->toDateTimeImmutable();

            // Its time passed before anything was sent: never charged, and never sent late (rule 4).
            if ($now >= $attempt->windowEndsAt) {
                return [$this->settlement->neverCharged($attempt, AttemptState::Abandoned, $now), false];
            }

            $attempt = $attempt->with(['state' => AttemptState::Charging, 'chargeClaimedAt' => $now]);
            $this->attempts->save($attempt, $now);

            return [$attempt, true];
        });

        /** @var Attempt $attempt */
        [$attempt, $mine] = $claimed;

        return AttemptViews::start($mine ? $this->call($attempt) : $attempt);
    }

    private function call(Attempt $attempt): Attempt
    {
        $store = $this->platform->store(StoreId::fromString($attempt->storeId));
        $access = $store === null ? null : $this->accesses->of($store, $attempt);

        if ($store === null || $access === null) {
            return $this->lost($attempt);
        }

        try {
            $answer = $this->client->charge($access, new ChargeRequest(
                $attempt->sessionId,
                Money::of($attempt->amountMinor, $attempt->currencyCode)->toDecimal($store->currencyExponent),
                $attempt->currencyCode,
                $attempt->idempotencyKey,
                $attempt->id,
                $attempt->ownerId,
                $attempt->windowEndsAt,
                $attempt->locale,
                $attempt->returnUrl,
                $this->own->noticeUrl($attempt->storeId, $attempt->mode),
            ));
        } catch (GatewayRefused $refused) {
            return $this->refused($attempt, $refused->reason);
        } catch (GatewayKeyRefused|KeyPermissionMissing) {
            $this->accesses->refused($access);

            return $this->refused($attempt, 'MyFatoorah refused the key.');
        } catch (GatewayUnreachable) {
            return $this->lost($attempt);
        }

        return $this->charged($attempt, $answer);
    }

    private function charged(Attempt $attempt, ChargeAnswer $answer): Attempt
    {
        return $this->db->transaction(function () use ($attempt, $answer): Attempt {
            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $current = $this->attempts->lock($attempt->id) ?? throw new AttemptNotFound;
            // A notice can beat the answer here: what it settled stays, and only what we did not know is added.
            $current = $current->with([
                'invoiceId' => $current->invoiceId ?? $answer->invoiceId,
                'gatewayPaymentId' => $current->gatewayPaymentId ?? $answer->paymentId,
                'frameUrl' => $answer->frameUrl,
                'state' => $current->state === AttemptState::Charging || $current->state === AttemptState::Unknown ? AttemptState::Open : $current->state,
            ]);

            return $this->settlement->booked($current, $now);
        });
    }

    /** The first call said no: nothing is charged, and Sales releases at once (§1.4 step 4). */
    private function refused(Attempt $attempt, string $reason): Attempt
    {
        return $this->db->transaction(function () use ($attempt, $reason): Attempt {
            $current = $this->attempts->lock($attempt->id) ?? throw new AttemptNotFound;

            return $current->state === AttemptState::Charging
                ? $this->settlement->neverCharged($current, AttemptState::Refused, CarbonImmutable::now()->toDateTimeImmutable(), $reason)
                : $current;
        });
    }

    /** No word: never "failed". The checks settle it through the session (rule 4). */
    private function lost(Attempt $attempt): Attempt
    {
        return $this->db->transaction(function () use ($attempt): Attempt {
            $current = $this->attempts->lock($attempt->id) ?? throw new AttemptNotFound;

            return $current->state === AttemptState::Charging
                ? $this->settlement->booked($current->with(['state' => AttemptState::Unknown]), CarbonImmutable::now()->toDateTimeImmutable())
                : $current;
        });
    }
}
