<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Storefront;

use Modules\Access\Public\Contracts\ShopperLine;
use Modules\Access\Public\Dto\ShopperLineDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\ShopperLineTone;
use Modules\B2B\Application\Query\ShopLine\CompanyStandings;
use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * The line under the shop's header for a company account that cannot order (b2b.md §4.4, amendment
 * 14(c)), linking to the company page. Asked on every shop page, so it answers from what Access
 * hands it first: an individual account, and one whose email is not confirmed yet — Access's own
 * mark says that — cost no query at all.
 *
 * Nothing once approved, even while the company has an unsent change of its details: it can still
 * order (owner, 2026-09-29).
 */
final readonly class CompanyShopperLine implements ShopperLine
{
    public const string ROUTE = 'storefront.company';

    public function __construct(
        private CompanyStandings $standings,
    ) {}

    public function lineFor(string $customerId, AccountType $accountType, bool $emailVerified): ?ShopperLineDto
    {
        if ($accountType !== AccountType::Company || ! $emailVerified) {
            return null;
        }

        $standing = $this->standings->of($customerId);

        return match ($standing->status) {
            null => $standing->draftOpen
                ? self::line('finish', ShopperLineTone::Info)
                : self::line('continue', ShopperLineTone::Info),
            CompanyStatus::Pending => self::line('pending', ShopperLineTone::Warn),
            CompanyStatus::Rejected => self::line('rejected', ShopperLineTone::Bad),
            CompanyStatus::Suspended => self::line('suspended', ShopperLineTone::Bad, ['reason' => (string) $standing->statusReason]),
            CompanyStatus::Approved => null,
        };
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function line(string $key, ShopperLineTone $tone, array $values = []): ShopperLineDto
    {
        $text = __("b2b::shop_line.{$key}", $values);

        return new ShopperLineDto(is_string($text) ? $text : $key, self::ROUTE, $tone);
    }
}
