<?php

declare(strict_types=1);

use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;

/*
| What a line costs (pricing.md §1.6, §8 #1), case by case: the engine is pure, so every rule is a row.
| Amounts are in halalas; "now" is a fixed moment, and the windows are set around it.
*/

const PRICING_ENGINE_NOW = '2026-10-10 12:00:00';

function pricingEngineAt(string $offset = '+0 days'): DateTimeImmutable
{
    return (new DateTimeImmutable(PRICING_ENGINE_NOW, new DateTimeZone('UTC')))->modify($offset);
}

function pricingEngineMoney(int $halalas): Money
{
    return Money::of($halalas, 'SAR');
}

function pricingEngineBase(int $amount, SaleMode $mode = SaleMode::Retail): Candidate
{
    return new Candidate($mode, 1, pricingEngineMoney($amount), PriceKind::Base);
}

function pricingEngineDated(PriceKind $kind, int $amount, string $from = '-1 day', ?string $until = '+5 days', bool $alwaysWins = false, SaleMode $mode = SaleMode::Retail, ?string $id = null): Candidate
{
    return new Candidate($mode, 1, pricingEngineMoney($amount), $kind, $alwaysWins, pricingEngineAt($from), $until === null ? null : pricingEngineAt($until), $id);
}

function pricingEngineBand(int $from, int $amount): Candidate
{
    return new Candidate(SaleMode::Wholesale, $from, pricingEngineMoney($amount), PriceKind::Quantity);
}

/**
 * @param  list<Candidate>  $candidates
 * @return array{int, int, PriceKind, ?string}|null list unit, unit, kind, ends at
 */
function pricingEngineResolve(array $candidates, SaleMode $mode = SaleMode::Retail, int $quantity = 1): ?array
{
    $price = PriceResolution::resolve($candidates, $mode, $quantity, pricingEngineAt());

    return $price === null ? null : [$price->listUnit->minorUnits, $price->unit->minorUnits, $price->kind, $price->endsAt?->format('Y-m-d H:i')];
}

/**
 * A case's candidates, checked: a dataset row reaches a test as a plain array.
 *
 * @param  array<mixed>  $items
 * @return list<Candidate>
 */
function pricingEngineCandidates(array $items): array
{
    $candidates = [];

    foreach ($items as $item) {
        if (! $item instanceof Candidate) {
            throw new InvalidArgumentException('A case holds candidates only.');
        }

        $candidates[] = $item;
    }

    return $candidates;
}

/**
 * @param  list<Candidate>  $candidates
 */
function pricingEngineEndsAt(array $candidates): ?string
{
    return PriceResolution::resolve($candidates, SaleMode::Retail, 1, pricingEngineAt())?->endsAt?->format('Y-m-d H:i');
}

it('prices a line', function (array $candidates, SaleMode $mode, int $quantity, ?array $expected) {
    expect(pricingEngineResolve(pricingEngineCandidates($candidates), $mode, $quantity))->toBe($expected);
})->with([
    'retail price only' => [[pricingEngineBase(10000)], SaleMode::Retail, 1, [10000, 10000, PriceKind::Base, null]],
    'no retail price, no price' => [[pricingEngineDated(PriceKind::Sale, 9000)], SaleMode::Retail, 1, null],
    'a sale wins, until its end' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000)], SaleMode::Retail, 1, [10000, 9000, PriceKind::Sale, '2026-10-15 12:00']],
    'a sale with no end lasts' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000, until: null)], SaleMode::Retail, 1, [10000, 9000, PriceKind::Sale, null]],
    'a cheaper category discount beats a sale' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000), pricingEngineDated(PriceKind::Category, 8500, until: '+2 days')], SaleMode::Retail, 1, [10000, 8500, PriceKind::Category, '2026-10-12 12:00']],
    'two sales overlap: the lower wins' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000), pricingEngineDated(PriceKind::Sale, 8800, until: '+1 day')], SaleMode::Retail, 1, [10000, 8800, PriceKind::Sale, '2026-10-11 12:00']],
    'a scheduled sale does not apply yet' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000, from: '+1 day')], SaleMode::Retail, 1, [10000, 10000, PriceKind::Base, null]],
    'an ended sale no longer applies' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000, from: '-5 days', until: '-1 day')], SaleMode::Retail, 1, [10000, 10000, PriceKind::Base, null]],
    'a sale ending exactly now no longer applies' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000, from: '-5 days', until: '+0 days')], SaleMode::Retail, 1, [10000, 10000, PriceKind::Base, null]],
    'a sale starting exactly now applies' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000, from: '+0 days')], SaleMode::Retail, 1, [10000, 9000, PriceKind::Sale, '2026-10-15 12:00']],
]);

it('gives a wholesale line its band, and takes the band as its list price', function (int $quantity, ?array $expected) {
    $candidates = [pricingEngineBase(10000, SaleMode::Wholesale), pricingEngineBand(20, 8500), pricingEngineBand(100, 7800), pricingEngineBand(500, 7000)];

    expect(pricingEngineResolve($candidates, SaleMode::Wholesale, $quantity))->toBe($expected);
})->with([
    'under the first band: the retail price' => [19, [10000, 10000, PriceKind::Base, null]],
    'at the first band' => [20, [8500, 8500, PriceKind::Quantity, null]],
    'just under the second' => [99, [8500, 8500, PriceKind::Quantity, null]],
    'at the second' => [100, [7800, 7800, PriceKind::Quantity, null]],
    'past the last' => [9000, [7000, 7000, PriceKind::Quantity, null]],
]);

it('never gives a retail line a band', function () {
    expect(pricingEngineResolve([pricingEngineBase(10000), pricingEngineBase(10000, SaleMode::Wholesale), pricingEngineBand(1, 5000)], SaleMode::Retail, 50))
        ->toBe([10000, 10000, PriceKind::Base, null]);
});

it('applies a category discount to the lines it covers only', function () {
    $wholesaleOnly = [pricingEngineBase(10000), pricingEngineBase(10000, SaleMode::Wholesale), pricingEngineBand(20, 7800), pricingEngineDated(PriceKind::Category, 7000, mode: SaleMode::Wholesale)];

    // On a wholesale line it competes with the band, and its list price stays the band.
    expect(pricingEngineResolve($wholesaleOnly, SaleMode::Wholesale, 20))->toBe([7800, 7000, PriceKind::Category, '2026-10-15 12:00'])
        // A discount for wholesale lines never reaches a retail line.
        ->and(pricingEngineResolve($wholesaleOnly, SaleMode::Retail, 20))->toBe([10000, 10000, PriceKind::Base, null]);
});

it('shows the offer when prices are equal: sale, then category discount, then band, then retail', function (array $candidates, SaleMode $mode, PriceKind $kind) {
    expect(PriceResolution::resolve(pricingEngineCandidates($candidates), $mode, 100, pricingEngineAt())?->kind)->toBe($kind);
})->with([
    'sale and category discount' => [[pricingEngineBase(10000), pricingEngineDated(PriceKind::Category, 9000), pricingEngineDated(PriceKind::Sale, 9000)], SaleMode::Retail, PriceKind::Sale],
    'category discount and band' => [[pricingEngineBase(10000, SaleMode::Wholesale), pricingEngineBand(20, 9000), pricingEngineDated(PriceKind::Category, 9000, mode: SaleMode::Wholesale)], SaleMode::Wholesale, PriceKind::Category],
    'band and retail price' => [[pricingEngineBase(9000, SaleMode::Wholesale), pricingEngineBand(20, 9000)], SaleMode::Wholesale, PriceKind::Quantity],
    'sale and retail price' => [[pricingEngineBase(9000), pricingEngineDated(PriceKind::Sale, 9000)], SaleMode::Retail, PriceKind::Sale],
]);

it('keeps, of two equal sales, the one that lasts longest', function () {
    $shorter = pricingEngineDated(PriceKind::Sale, 9000, until: '+1 day');
    $longer = pricingEngineDated(PriceKind::Sale, 9000, until: '+9 days');
    $endless = pricingEngineDated(PriceKind::Sale, 9000, until: null);

    expect(pricingEngineEndsAt([pricingEngineBase(10000), $shorter, $longer]))->toBe('2026-10-19 12:00')
        ->and(pricingEngineEndsAt([pricingEngineBase(10000), $longer, $shorter]))->toBe('2026-10-19 12:00')
        ->and(pricingEngineEndsAt([pricingEngineBase(10000), $shorter, $endless]))->toBeNull();
});

describe('"always wins while on" (owner, 2026-10-08/09)', function () {
    it('beats a cheaper sale and a cheaper category discount', function () {
        $candidates = [
            pricingEngineBase(10000),
            pricingEngineDated(PriceKind::Sale, 9500, alwaysWins: true, until: '+3 days'),
            pricingEngineDated(PriceKind::Sale, 9000),
            pricingEngineDated(PriceKind::Category, 8500),
        ];

        expect(pricingEngineResolve($candidates))->toBe([10000, 9500, PriceKind::Sale, '2026-10-13 12:00']);
    });

    it('stops winning once the retail price drops below it, as any sale does (§1.3)', function () {
        expect(pricingEngineResolve([pricingEngineBase(9000), pricingEngineDated(PriceKind::Category, 9500, alwaysWins: true), pricingEngineDated(PriceKind::Sale, 8000)]))
            ->toBe([9000, 9000, PriceKind::Base, null]);
    });

    it('wins a tie with the retail price, so the shopper sees the offer', function () {
        expect(pricingEngineResolve([pricingEngineBase(9500), pricingEngineDated(PriceKind::Sale, 9500, alwaysWins: true), pricingEngineDated(PriceKind::Sale, 9000)]))
            ->toBe([9500, 9500, PriceKind::Sale, '2026-10-15 12:00']);
    });

    it('never beats a cheaper wholesale band: a wholesale line pays the lower of the two', function () {
        $candidates = [pricingEngineBase(10000, SaleMode::Wholesale), pricingEngineBand(20, 7800), pricingEngineDated(PriceKind::Sale, 9000, alwaysWins: true, mode: SaleMode::Wholesale)];

        expect(pricingEngineResolve($candidates, SaleMode::Wholesale, 20))->toBe([7800, 7800, PriceKind::Quantity, null]);
    });

    it('wins on a wholesale line when it is the lower', function () {
        $candidates = [pricingEngineBase(10000, SaleMode::Wholesale), pricingEngineBand(20, 7800), pricingEngineDated(PriceKind::Sale, 7500, alwaysWins: true, mode: SaleMode::Wholesale), pricingEngineDated(PriceKind::Sale, 7000, mode: SaleMode::Wholesale)];

        expect(pricingEngineResolve($candidates, SaleMode::Wholesale, 20))->toBe([7800, 7500, PriceKind::Sale, '2026-10-15 12:00']);
    });

    it('cuts short a running price when one is scheduled on the line, which would replace it', function () {
        $candidates = [pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9000, until: '+10 days'), pricingEngineDated(PriceKind::Sale, 9500, from: '+3 days', until: '+20 days', alwaysWins: true)];

        expect(pricingEngineResolve($candidates))->toBe([10000, 9000, PriceKind::Sale, '2026-10-13 12:00']);
    });

    it('ends even the retail price\'s run when one is scheduled', function () {
        expect(pricingEngineResolve([pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9500, from: '+2 days', alwaysWins: true)]))
            ->toBe([10000, 10000, PriceKind::Base, '2026-10-12 12:00']);
    });

    it('takes no notice of one scheduled on another mode, or of an ordinary sale scheduled later', function () {
        $candidates = [pricingEngineBase(10000), pricingEngineDated(PriceKind::Sale, 9500, from: '+2 days', alwaysWins: true, mode: SaleMode::Wholesale), pricingEngineDated(PriceKind::Sale, 9000, from: '+1 day')];

        expect(pricingEngineResolve($candidates))->toBe([10000, 10000, PriceKind::Base, null]);
    });
});
