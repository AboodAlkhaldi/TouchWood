<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Wholesale bands that break a rule (pricing.md §1.4, §7), **naming the rule** (owner Q12, 2026-10-19) so
 * the bands dialog says which (`rules.<rule>` in the module's words):
 *
 * - `first_band` - the first band does not start at the product's wholesale minimum;
 * - `minimum` - a band starts below the wholesale minimum;
 * - `maximum` - a band starts above the size's wholesale maximum, when it has one;
 * - `order` - a band does not cost less than the band before it;
 * - `below_retail` - the first band does not cost less than the retail price;
 * - `too_many` - more than ten bands.
 */
final class BandsInvalid extends PricingError
{
    public const string FIRST_BAND = 'first_band';

    public const string MINIMUM = 'minimum';

    public const string MAXIMUM = 'maximum';

    public const string ORDER = 'order';

    public const string BELOW_RETAIL = 'below_retail';

    public const string TOO_MANY = 'too_many';

    /** The most bands a size may have (owner Q12). */
    public const int MOST = 10;

    /**
     * @param  list<string>  $variants  the size, named for its row
     * @param  string  $retail  the retail price, written in the store's decimals, for `below_retail`
     */
    public function __construct(
        public readonly string $rule = self::ORDER,
        public readonly ?int $minimum = null,
        public readonly ?int $maximum = null,
        public readonly string $retail = '',
        public readonly array $variants = [],
    ) {
        parent::__construct("Wholesale bands break the rule \"{$rule}\": the first band starts at the wholesale minimum and costs less than the retail price; each next band starts higher, at most at the wholesale maximum, and costs less; at most ".self::MOST.' bands.');
    }

    public function type(): string
    {
        return 'pricing.bands_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        $context = [
            'rule' => $this->rule,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'retail' => $this->retail,
            'most' => self::MOST,
        ];

        return $this->variants === [] ? $context : [...$context, 'variants' => implode(',', $this->variants)];
    }
}
