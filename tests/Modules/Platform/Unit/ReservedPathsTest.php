<?php

declare(strict_types=1);

use Modules\Platform\Application\Routing\InMemoryReservedPaths;

function storePattern(InMemoryReservedPaths $paths): string
{
    return '#^'.$paths->routePattern().'$#';
}

it('collects the paths every module reserves', function () {
    $paths = new InMemoryReservedPaths;
    $paths->reserve('platform', 'up', 'admin');
    $paths->reserve('payments', 'webhooks');

    expect($paths->all())->toBe(['admin', 'up', 'webhooks'])
        ->and($paths->isReserved('webhooks'))->toBeTrue()
        ->and($paths->isReserved('sa'))->toBeFalse();
});

it('lets several modules share a path, such as the admin panel', function () {
    $paths = new InMemoryReservedPaths;
    $paths->reserve('platform', 'admin');
    $paths->reserve('catalog', 'admin');

    expect($paths->all())->toBe(['admin']);
});

it('keeps reserved paths out of the store pattern, but not codes that only start like one', function () {
    $paths = new InMemoryReservedPaths;
    $paths->reserve('platform', 'up', 'api');

    expect(preg_match(storePattern($paths), 'up'))->toBe(0)
        ->and(preg_match(storePattern($paths), 'api'))->toBe(0)
        ->and(preg_match(storePattern($paths), 'upx'))->toBe(1)
        ->and(preg_match(storePattern($paths), 'sa'))->toBe(1);
});

it('matches any 2 to 8 letter code when nothing is reserved', function () {
    expect(preg_match(storePattern(new InMemoryReservedPaths), 'admin'))->toBe(1);
});

it('refuses a malformed segment', function (string $segment) {
    (new InMemoryReservedPaths)->reserve('platform', $segment);
})->throws(LogicException::class)->with(['', 'Admin', '/admin', 'admin/login', '1up']);

it('refuses a reservation made after the pattern was built', function () {
    $paths = new InMemoryReservedPaths;
    $paths->freeze();

    $paths->reserve('access', 'login');
})->throws(LogicException::class, 'register()');
