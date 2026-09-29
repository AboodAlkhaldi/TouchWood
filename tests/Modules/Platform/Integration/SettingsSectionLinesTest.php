<?php

declare(strict_types=1);

use Modules\Platform\Application\Settings\InMemorySettingsSectionLines;
use Modules\Platform\Public\Contracts\SettingsSectionLine;
use Modules\Platform\Public\Contracts\SettingsSectionLines;
use Shared\Domain\ValueObject\StoreId;

/*
| A module's line at the top of its settings section (platform.md §1.3, §9.4; asked for by B2B step
| 5): registered once per module, resolved only when the page is shown, told the store in the header.
*/

final class SettingsSectionLinesTestLine implements SettingsSectionLine
{
    public function line(?StoreId $store): ?string
    {
        return $store === null ? null : "In {$store->value}";
    }
}

it('gives the line a module registered, told the store shown; none to a module that registered none', function () {
    $lines = new InMemorySettingsSectionLines(app());
    $lines->register('testing', SettingsSectionLinesTestLine::class);
    $store = StoreId::fromString('01j8z3k4m5n6p7q8r9s0t1v2w5');

    expect($lines->for('testing')?->line($store))->toBe('In 01j8z3k4m5n6p7q8r9s0t1v2w5')
        ->and($lines->for('testing')?->line(null))->toBeNull()
        ->and($lines->for('elsewhere'))->toBeNull();
});

it('refuses a class that is not a line, and a module\'s second line', function () {
    $lines = new InMemorySettingsSectionLines(app());
    $lines->register('testing', SettingsSectionLinesTestLine::class);

    expect(fn () => $lines->register('other', stdClass::class))->toThrow(LogicException::class, 'does not implement')
        ->and(fn () => $lines->register('testing', SettingsSectionLinesTestLine::class))->toThrow(LogicException::class, 'already has');
});

it('is one registry for the whole application, behind Platform\'s public contract', function () {
    expect(app(SettingsSectionLines::class))->toBe(app(InMemorySettingsSectionLines::class));
});
