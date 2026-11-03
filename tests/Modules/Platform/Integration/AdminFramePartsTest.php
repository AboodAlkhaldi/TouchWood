<?php

declare(strict_types=1);

use Modules\Platform\Application\Routing\InMemoryAdminFrameParts;
use Modules\Platform\Public\Contracts\AdminFramePart;
use Modules\Platform\Public\Contracts\AdminFrameParts;

/*
| The panel frame's parts (ops.md §4.15 O1): what modules put in every panel page's frame, registered in
| their providers, carried by module; Platform never reads what one holds. Ops' bell is checked in its
| BellScreensTest.
*/

final class AdminFramePartsTestPart implements AdminFramePart
{
    public function partFor(string $locale): array
    {
        return ['locale' => $locale];
    }
}

it('carries each registered part by its module, asked in this language', function () {
    $parts = new InMemoryAdminFrameParts(app());
    $parts->register('one', AdminFramePartsTestPart::class);

    expect($parts->for('ar'))->toBe(['one' => ['locale' => 'ar']])
        ->and((new InMemoryAdminFrameParts(app()))->for('en'))->toBe([]);
});

it('refuses a class that is not a part, and a module registering twice', function () {
    $parts = new InMemoryAdminFrameParts(app());
    $parts->register('one', AdminFramePartsTestPart::class);

    expect(fn () => $parts->register('two', stdClass::class))->toThrow(LogicException::class, 'does not implement')
        ->and(fn () => $parts->register('one', AdminFramePartsTestPart::class))->toThrow(LogicException::class, 'already has a part');
});

it('is one list for the whole application', function () {
    expect(app(AdminFrameParts::class))->toBe(app(InMemoryAdminFrameParts::class));
});
