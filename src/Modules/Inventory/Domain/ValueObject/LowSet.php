<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * **A store's low set** (inventory.md §1.7, amendment 1): the sizes on its Low Stock list, each **out** -
 * nothing free - or **low** - 1 or more free. Kept per store by the recount, to say what changed at the
 * next one. Each list holds a size once, in id order.
 */
final readonly class LowSet
{
    /** @var list<string> */
    public array $low;

    /** @var list<string> */
    public array $out;

    /**
     * @param  list<string>  $low
     * @param  list<string>  $out
     */
    public function __construct(array $low, array $out)
    {
        $this->out = self::sorted($out);
        // A size is out or low, never both: out wins.
        $this->low = self::sorted(array_values(array_diff($low, $this->out)));
    }

    /**
     * What changed from this set to `$now` (§1.7): **became low** - off the list, now low; **ran out** - now
     * out, and not out before (from low, LOW→OUT, or from off the list); **came back** - on the list before,
     * now neither low nor out, unless it is one of `$gone`: a size that left the list for another reason
     * (archived, switched off with no stock row, gone from Catalog) leaves silently. OUT→LOW is in nothing.
     *
     * @param  list<string>  $gone
     */
    public function changesTo(self $now, array $gone = []): LowChanges
    {
        $wasLow = array_flip($this->low);
        $wasOut = array_flip($this->out);
        $isOn = array_flip([...$now->low, ...$now->out]);
        $leftSilently = array_flip($gone);

        $becameLow = array_values(array_filter($now->low, static fn (string $id): bool => ! isset($wasLow[$id]) && ! isset($wasOut[$id])));
        $ranOut = array_values(array_filter($now->out, static fn (string $id): bool => ! isset($wasOut[$id])));
        $cameBack = array_values(array_filter([...$this->low, ...$this->out], static fn (string $id): bool => ! isset($isOn[$id]) && ! isset($leftSilently[$id])));

        return new LowChanges($becameLow, $ranOut, self::sorted($cameBack));
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
