<?php

declare(strict_types=1);

use Modules\Inventory\Domain\ValueObject\LowSet;

/*
| A store's low set and what changed from one to the next (inventory.md §1.7, amendment 1): every move
| between off the list, low and out.
*/

it('says each move between off the list, low and out in exactly one list, or none', function (array $before, array $now, array $expected) {
    $changes = (new LowSet(...$before))->changesTo(new LowSet(...$now));

    expect([$changes->becameLow, $changes->ranOut, $changes->cameBack])->toBe($expected)
        ->and($changes->isEmpty())->toBe($expected === [[], [], []]);
})->with([
    'off -> low: became low' => [[[], []], [['s'], []], [['s'], [], []]],
    'off -> out: ran out' => [[[], []], [[], ['s']], [[], ['s'], []]],
    'low -> out: ran out' => [[['s'], []], [[], ['s']], [[], ['s'], []]],
    'out -> low: nothing' => [[[], ['s']], [['s'], []], [[], [], []]],
    'low -> off: came back' => [[['s'], []], [[], []], [[], [], ['s']]],
    'out -> off: came back' => [[[], ['s']], [[], []], [[], [], ['s']]],
    'low -> low: nothing' => [[['s'], []], [['s'], []], [[], [], []]],
    'out -> out: nothing' => [[[], ['s']], [[], ['s']], [[], [], []]],
]);

it('lets a size gone for another reason leave silently, and keeps each list in id order, a size out or low never both', function () {
    $changes = (new LowSet(['c', 'a'], ['b']))->changesTo(new LowSet([], []), ['a']);
    $both = new LowSet(['b', 'a', 'a'], ['a']);

    expect($changes->cameBack)->toBe(['b', 'c'])
        ->and($changes->entries())->toBe([['b', 'BACK'], ['c', 'BACK']])
        ->and([$both->low, $both->out])->toBe([['b'], ['a']]);
});
