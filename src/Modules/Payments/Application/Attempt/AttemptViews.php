<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Attempt;

use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Payments\Public\Dto\PaymentStartDto;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Modules\Payments\Public\Enums\PaymentState;

/**
 * An attempt as other modules see it (payments.md §2.1).
 */
final class AttemptViews
{
    public static function start(Attempt $attempt): PaymentStartDto
    {
        // A bank page is offered only while its payment can still be waited for.
        return new PaymentStartDto($attempt->id, $attempt->state === AttemptState::Open ? $attempt->frameUrl : null, $attempt->windowEndsAt, self::state($attempt), $attempt->nothingCharged, $attempt->refusedReason);
    }

    public static function state(Attempt $attempt): PaymentState
    {
        return match ($attempt->state) {
            AttemptState::Creating, AttemptState::Charging => PaymentState::Open,
            AttemptState::Open => PaymentState::InProgress,
            AttemptState::Unknown => PaymentState::Unknown,
            AttemptState::Succeeded => $attempt->currencyRefused ? PaymentState::NotAccepted : PaymentState::Succeeded,
            AttemptState::Failed, AttemptState::Refused => PaymentState::Failed,
            AttemptState::Expired, AttemptState::Abandoned => PaymentState::Expired,
        };
    }

    public static function page(?Attempt $attempt): PaymentStateDto
    {
        return $attempt === null
            ? new PaymentStateDto(PaymentState::None, null, null, null, false)
            : new PaymentStateDto(self::state($attempt), $attempt->windowEndsAt, $attempt->waitEndsAt, $attempt->holdEndsAt, $attempt->nothingCharged, $attempt->id, $attempt->transactionId);
    }
}
