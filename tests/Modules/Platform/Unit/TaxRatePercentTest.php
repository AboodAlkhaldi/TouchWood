<?php

declare(strict_types=1);

use Modules\Platform\Presentation\Http\Controller\StoresController;
use Modules\Platform\Presentation\Http\Resource\StorePages;

/*
| A tax rate on its way to a screen and back (frontend.md 3.5, E2).
|
| Platform keeps the rate as basis points so no float is involved (platform.md 1.1). A person reads
| and writes a percentage. These two turn one into the other, and they are pure, so they are tested
| here rather than through a browser.
*/

it('writes basis points as the percentage a person reads', function (int $basisPoints, string $percent) {
    expect(StorePages::percent($basisPoints))->toBe($percent);
})->with([
    'a whole percentage' => [1500, '15'],
    'half a percent' => [1550, '15.5'],
    'two decimals' => [1555, '15.55'],
    'nothing at all' => [0, '0'],
    'the smallest step' => [1, '0.01'],
    'everything' => [10000, '100'],
]);

it('reads the percentage a person wrote as basis points', function (string $percent, int $basisPoints) {
    expect(StoresController::basisPoints($percent))->toBe($basisPoints);
})->with([
    'a whole percentage' => ['15', 1500],
    'half a percent' => ['15.5', 1550],
    'two decimals' => ['15.55', 1555],
    'trailing space' => [' 15 ', 1500],
    'nothing at all' => ['0', 0],
    'more decimals than a rate has' => ['15.559', 1555],
]);

it('survives the round trip, which is what a person editing a store actually does', function (int $basisPoints) {
    expect(StoresController::basisPoints(StorePages::percent($basisPoints)))->toBe($basisPoints);
})->with([0, 1, 50, 500, 1500, 1550, 1555, 10000]);
