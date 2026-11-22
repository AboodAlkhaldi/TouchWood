<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * **What changed in a store's low set** (inventory.md §1.7, amendment 1): the sizes that became low, ran
 * out and came back, each list in id order. At one recount a size is in one list at most; over a range
 * of the log (`LowSets::changes`) a size that changed several times may be in more.
 */
final readonly class LowChanges
{
    public const string LOW = 'LOW';

    public const string OUT = 'OUT';

    public const string BACK = 'BACK';

    /**
     * @param  list<string>  $becameLow
     * @param  list<string>  $ranOut
     * @param  list<string>  $cameBack
     */
    public function __construct(
        public array $becameLow,
        public array $ranOut,
        public array $cameBack,
    ) {}

    public function isEmpty(): bool
    {
        return $this->becameLow === [] && $this->ranOut === [] && $this->cameBack === [];
    }

    /**
     * Each change as the log keeps it: the size and its kind.
     *
     * @return list<array{string, string}>
     */
    public function entries(): array
    {
        return [
            ...array_map(static fn (string $id): array => [$id, self::LOW], $this->becameLow),
            ...array_map(static fn (string $id): array => [$id, self::OUT], $this->ranOut),
            ...array_map(static fn (string $id): array => [$id, self::BACK], $this->cameBack),
        ];
    }
}
