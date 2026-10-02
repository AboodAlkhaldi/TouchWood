<?php

declare(strict_types=1);

use Modules\B2B\Domain\ValueObject\FlaggedField;

/*
| B2B step 7: the fields the Reject Application modal offers to mark (b2b.md §1.2, §4.6) are exactly
| the ones a rejection may flag. The list is written in the screen's source, so a field added to the
| domain and forgotten there — or one the screen offers and the domain refuses — fails here rather
| than on a reviewer's screen.
*/

it('offers to mark exactly the fields a rejection may flag', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/resources/js/pages/B2B/Admin/Companies/Decisions.tsx');

    $found = preg_match('/const FIELDS = \[([^\]]*)\] as const;/', $source, $match);
    // A guard over a file must find what it checks, or it passes over nothing.
    expect($found)->toBe(1);
    preg_match_all("/'([a-z_]+)'/", $match[1] ?? '', $fields);

    expect($fields[1])->toBe(array_map(static fn (FlaggedField $field): string => $field->value, FlaggedField::cases()));
});
