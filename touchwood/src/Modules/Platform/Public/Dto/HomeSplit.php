<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A whole and its parts (dashboard.md §2 D, I; §6.1's "split"): a ring with the total in its middle, or a bar
 * split into its parts.
 */
#[TypeScript]
final readonly class HomeSplit
{
    public const string RING = 'ring';

    public const string BAR = 'bar';

    /**
     * @param  list<HomeSplitPart>  $parts  largest first, as the section orders them
     */
    public function __construct(
        /** Already in the reader's language. */
        public string $label,
        /** self::RING or self::BAR. */
        public string $shape,
        public array $parts,
        public ?HomeValue $total = null,
    ) {}

    /**
     * Each value's share of their sum, in percent to one decimal; 0 for every part of a sum of nothing.
     *
     * @param  list<int>  $values
     * @return list<float>
     */
    public static function shares(array $values): array
    {
        $sum = array_sum($values);

        return array_map(static fn (int $value): float => $sum === 0 ? 0.0 : round($value / $sum * 100, 1), $values);
    }
}
