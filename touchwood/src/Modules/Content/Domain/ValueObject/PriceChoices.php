<?php

declare(strict_types=1);

namespace Modules\Content\Domain\ValueObject;

use Modules\Content\Domain\Exception\InvalidContentInput;

/**
 * A draft run's prices as staff chose them (content.md §1.7 #4, #14; §5 `price_choices`): the lines (`RETAIL`,
 * `WHOLESALE`, `BOTH`), the targets - a product, size, category or brand by id with a percentage off in basis points,
 * or a size with a fixed price in the store's minor units - and the products left out of its categories and brands.
 * Ids and amounts only, no media. **Their shape is checked here; whether each id exists, is archived or goes past the
 * ceiling is Pricing's to say** (its preview, then its save at Schedule).
 */
final readonly class PriceChoices
{
    public const array LINES = ['RETAIL', 'WHOLESALE', 'BOTH'];

    public const array KINDS = ['PRODUCT', 'SIZE', 'CATEGORY', 'BRAND'];

    /** More than any campaign typed by hand would name (the build's pick). */
    public const int MAX_TARGETS = 500;

    public const int MAX_LEFT_OUT = 500;

    /**
     * @param  list<array{kind: string, id: string, percentBp: int|null, amountMinor: int|null}>  $targets
     * @param  list<string>  $leftOut
     */
    private function __construct(
        public string $lines,
        public array $targets,
        public array $leftOut,
    ) {}

    /**
     * Choices as a form or the stored column gives them, refused unless each part has its shape.
     *
     * @param  array<array-key, mixed>  $value  `{lines, targets: [{kind, id, percentBp?, amountMinor?}], leftOut: [ids]}`
     *
     * @throws InvalidContentInput
     */
    public static function of(array $value): self
    {
        $lines = $value['lines'] ?? null;

        if (! is_string($lines) || ! in_array($lines, self::LINES, true)) {
            throw new InvalidContentInput('lines', InvalidContentInput::CHOICE);
        }

        $targets = is_array($value['targets'] ?? null) ? array_values($value['targets']) : null;
        $leftOut = is_array($value['leftOut'] ?? []) ? array_values($value['leftOut'] ?? []) : null;

        if ($targets === null || $leftOut === null) {
            throw new InvalidContentInput('targets', InvalidContentInput::FORMAT);
        }

        if (count($targets) > self::MAX_TARGETS || count($leftOut) > self::MAX_LEFT_OUT) {
            throw new InvalidContentInput(count($targets) > self::MAX_TARGETS ? 'targets' : 'left_out', InvalidContentInput::TOO_MANY, values: ['max' => count($targets) > self::MAX_TARGETS ? self::MAX_TARGETS : self::MAX_LEFT_OUT]);
        }

        $seen = [];
        $written = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                throw new InvalidContentInput('targets', InvalidContentInput::FORMAT);
            }

            $kind = $target['kind'] ?? null;
            $id = self::id($target['id'] ?? null);

            if (! is_string($kind) || ! in_array($kind, self::KINDS, true) || $id === null) {
                throw new InvalidContentInput('targets', InvalidContentInput::FORMAT);
            }

            $percent = $target['percentBp'] ?? null;
            $amount = $target['amountMinor'] ?? null;

            // Exactly one of a percentage (0.01% to 99.99%) and a fixed price, the price on a size only.
            $percentOk = is_int($percent) && $percent >= 1 && $percent <= 9999 && $amount === null;
            $amountOk = is_int($amount) && $amount > 0 && $percent === null && $kind === 'SIZE';

            if (! $percentOk && ! $amountOk) {
                throw new InvalidContentInput('targets', InvalidContentInput::RANGE, values: ['min' => 1, 'max' => 9999]);
            }

            if (isset($seen["{$kind}:{$id}"])) {
                throw new InvalidContentInput('targets', InvalidContentInput::DUPLICATE);
            }

            $seen["{$kind}:{$id}"] = true;
            $written[] = ['kind' => $kind, 'id' => $id, 'percentBp' => $percentOk ? $percent : null, 'amountMinor' => $amountOk ? $amount : null];
        }

        $left = [];

        foreach ($leftOut as $product) {
            $product = self::id($product);

            if ($product === null) {
                throw new InvalidContentInput('left_out', InvalidContentInput::FORMAT);
            }

            $left[$product] = $product;
        }

        return new self($lines, $written, array_values($left));
    }

    /** No target: the run sets no price (§1.7 #16: a campaign with no prices may be scheduled). */
    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    /**
     * @return list<string> the ids of the targets of this kind
     */
    public function idsOf(string $kind): array
    {
        return array_values(array_map(static fn (array $target): string => $target['id'], array_filter($this->targets, static fn (array $target): bool => $target['kind'] === $kind)));
    }

    /**
     * @return array{lines: string, targets: list<array{kind: string, id: string, percentBp: int|null, amountMinor: int|null}>, leftOut: list<string>}
     */
    public function toArray(): array
    {
        return ['lines' => $this->lines, 'targets' => $this->targets, 'leftOut' => $this->leftOut];
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\z/', $value) === 1 ? strtolower($value) : null;
    }
}
