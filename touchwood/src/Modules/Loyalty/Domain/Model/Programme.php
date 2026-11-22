<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model;

use Modules\Loyalty\Public\Enums\Audience;
use Modules\Loyalty\Public\Enums\RedemptionMode;

/**
 * A store's points programme as it reads now (loyalty.md §1.5) — read when it is used, so a change
 * never rewrites what was already earned or spent — with the store's currency and its exponent.
 */
final readonly class Programme
{
    public function __construct(
        public bool $enabled,
        public int $earnPerUnit,
        public int $pointsPerUnitOff,
        public int $expiryMonths,
        public int $minimumRedemption,
        public int $maxRedemptionPercent,
        public bool $publicPartial,
        public bool $companyPartial,
        public string $currencyCode,
        public int $exponent,
    ) {}

    /** Each audience reads its own switch (§1.5). */
    public function modeFor(Audience $audience): RedemptionMode
    {
        $partial = $audience === Audience::Company ? $this->companyPartial : $this->publicPartial;

        return $partial ? RedemptionMode::Partial : RedemptionMode::AllOrNothing;
    }
}
