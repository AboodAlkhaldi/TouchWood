<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Promotions\Application\Command\ChangeGiftLevel\ChangeGiftLevel;
use Modules\Promotions\Application\Command\ChangeGiftLevel\ChangeGiftLevelHandler;
use Modules\Promotions\Application\Command\CreateGiftLevel\CreateGiftLevel;
use Modules\Promotions\Application\Command\CreateGiftLevel\CreateGiftLevelHandler;
use Modules\Promotions\Application\Command\GiftLevelFields;
use Modules\Promotions\Application\Command\SwitchGiftLevel\SwitchGiftLevel;
use Modules\Promotions\Application\Command\SwitchGiftLevel\SwitchGiftLevelHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\GiftLevelClash;
use Modules\Promotions\Domain\Exception\GiftLevelNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Public\Dto\GiftOfferDto;
use Modules\Promotions\Public\Enums\Audience;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Gift levels (promotions.md §1.7, §1.8, §3, §5, §7; the gift scenarios of §8): staff create, change
| and switch a store's levels — the gift a variant of a ready product, one amount one gift for the same
| buyers and dates — each audited, in their own stores, an off store's for Super Admins alone; Sales
| reads the levels an order reaches, highest first; the database holds the same rules.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Two gifts: variants of one ready product.
 *
 * @return array{0: string, 1: string}
 */
function gifts(): array
{
    [$pen, $mug] = Px::ready(['60 cm', '80 cm'])['variants'];

    return [$pen, $mug];
}

/**
 * @param  array<string, mixed>  $with  GiftLevelFields' arguments by name
 */
function giftFields(string $variantId, int $amountMinor = 1000_00, array $with = []): GiftLevelFields
{
    return new GiftLevelFields(...['amountMinor' => $amountMinor, 'variantId' => $variantId, 'forPublic' => true, 'forCompany' => true, ...$with]);
}

/**
 * @param  list<string>  $stores
 */
function giftManager(array $stores = ['sa']): void
{
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::GIFT_MANAGE], $stores));
}

function giftCreate(GiftLevelFields $fields, string $storeCode = 'sa', bool $active = true): string
{
    return app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel(Fx::storeId($storeCode), $fields, $active));
}

/**
 * @return array<string, mixed>
 */
function giftAudit(string $action): array
{
    $row = DB::table('platform.audit_entries')->where('action', $action)->orderByDesc('id')->first() ?? throw new RuntimeException("No {$action} in the audit log.");

    return ['subject_type' => $row->subject_type, 'subject_id' => $row->subject_id, 'store_id' => $row->store_id, 'changes' => json_decode((string) $row->changes, true)];
}

/**
 * @return list<array{0: string, 1: int, 2: string}>
 */
function reached(int $goodsMinor, Audience $audience = Audience::Public, string $storeCode = 'sa'): array
{
    return array_map(
        static fn (GiftOfferDto $offer): array => [$offer->levelId, $offer->amount->minorUnits, $offer->variantId],
        Pr::api()->giftLevels(StoreId::fromString(Fx::storeId($storeCode)), $audience, Pr::money($goodsMinor, $storeCode)),
    );
}

describe('creating a level', function () {
    it('stores the level in the store\'s currency and audits it', function () {
        giftManager();
        [$pen] = gifts();

        $id = giftCreate(giftFields($pen, with: ['forCompany' => false, 'startsAt' => CarbonImmutable::parse('2026-11-01 12:00:00+03:00')]));
        $row = DB::table('promotions.gift_levels')->where('id', $id)->first() ?? throw new RuntimeException('No level stored.');
        $audit = giftAudit('promotions.gift_level.created');

        expect($row->amount_minor)->toBe(1000_00)
            ->and($row->currency_code)->toBe(Pr::currency())
            ->and($row->variant_id)->toBe($pen)
            ->and([(bool) $row->for_public, (bool) $row->for_company, (bool) $row->active])->toBe([true, false, true])
            ->and(CarbonImmutable::parse((string) $row->starts_at)->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 09:00:00')
            ->and($row->ends_at)->toBeNull()
            ->and($audit['subject_type'])->toBe('promotions.gift_level')
            ->and($audit['subject_id'])->toBe($id)
            ->and($audit['store_id'])->toBe(Fx::storeId('sa'))
            ->and($audit['changes']['amount_minor'])->toBe([null, 1000_00])
            ->and($audit['changes']['for_company'])->toBe([null, false])
            ->and($audit['changes']['active'])->toBe([null, true]);
    });

    it('refuses what a level cannot be, naming the field', function (Closure $fields, string $attribute) {
        giftManager();
        [$pen] = gifts();

        expect(Pr::thrown(fn () => giftCreate($fields($pen)), InvalidPromotionAttribute::class)->attribute)->toBe($attribute)
            ->and(DB::table('promotions.gift_levels')->count())->toBe(0);
    })->with([
        'no amount' => [fn (string $pen) => giftFields($pen, 0), 'amount'],
        'a negative amount' => [fn (string $pen) => giftFields($pen, -1), 'amount'],
        'nobody to give it to' => [fn (string $pen) => giftFields($pen, with: ['forPublic' => false, 'forCompany' => false]), 'buyers'],
        'the end at the start' => [fn (string $pen) => giftFields($pen, with: ['startsAt' => CarbonImmutable::parse('2026-11-01'), 'endsAt' => CarbonImmutable::parse('2026-11-01')]), 'dates'],
        'the end before the start' => [fn (string $pen) => giftFields($pen, with: ['startsAt' => CarbonImmutable::parse('2026-11-02'), 'endsAt' => CarbonImmutable::parse('2026-11-01')]), 'dates'],
        'a gift that is not an id' => [fn () => giftFields('pen'), 'gift_variant'],
        'an unknown gift' => [fn () => giftFields(strtolower((string) Str::ulid())), 'gift_variant'],
        'a draft product\'s variant' => [fn () => giftFields(Px::variant(Px::product('Hinge'), '7001')), 'gift_variant'],
        'an archived variant' => [function (string $pen) {
            Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($pen)));

            return giftFields($pen);
        }, 'gift_variant'],
    ]);

    it('takes a gift id as typed, in capitals and with spaces', function () {
        giftManager();
        [$pen] = gifts();

        $id = giftCreate(giftFields(' '.strtoupper($pen).' '));

        expect(DB::table('promotions.gift_levels')->where('id', $id)->value('variant_id'))->toBe($pen);
    });
});

describe('one amount, one gift', function () {
    it('refuses a second active level with the same amount for the same buyers and dates', function (array $first, array $second) {
        giftManager();
        [$pen, $mug] = gifts();
        giftCreate(giftFields($pen, with: $first));

        expect(fn () => giftCreate(giftFields($mug, with: $second)))->toThrow(GiftLevelClash::class)
            ->and(DB::table('promotions.gift_levels')->count())->toBe(1);
    })->with([
        'both for everyone, no dates' => [[], []],
        'individuals in both' => [['forCompany' => false], ['forCompany' => false]],
        'companies in both' => [['forPublic' => false], ['forPublic' => false]],
        'one for everyone, one for companies' => [[], ['forPublic' => false]],
        'dates that overlap by a second' => [
            ['startsAt' => CarbonImmutable::parse('2026-11-01 00:00:00'), 'endsAt' => CarbonImmutable::parse('2026-11-10 00:00:00')],
            ['startsAt' => CarbonImmutable::parse('2026-11-09 23:59:59'), 'endsAt' => CarbonImmutable::parse('2026-11-20 00:00:00')],
        ],
        'open-ended against dated' => [['startsAt' => CarbonImmutable::parse('2026-11-01')], ['endsAt' => CarbonImmutable::parse('2026-11-02')]],
        'dated inside open' => [[], ['startsAt' => CarbonImmutable::parse('2026-11-01'), 'endsAt' => CarbonImmutable::parse('2026-11-02')]],
    ]);

    it('takes levels that never meet: another amount, other buyers, dates end to end, or one switched off', function () {
        giftManager();
        [$pen, $mug] = gifts();
        giftCreate(giftFields($pen, with: ['forCompany' => false, 'startsAt' => CarbonImmutable::parse('2026-11-01'), 'endsAt' => CarbonImmutable::parse('2026-11-10')]));

        giftCreate(giftFields($mug, 2000_00));
        giftCreate(giftFields($mug, with: ['forPublic' => false]));
        giftCreate(giftFields($mug, with: ['forCompany' => false, 'startsAt' => CarbonImmutable::parse('2026-11-10'), 'endsAt' => CarbonImmutable::parse('2026-11-20')]));
        giftCreate(giftFields($mug, with: ['forCompany' => false, 'startsAt' => CarbonImmutable::parse('2026-10-20'), 'endsAt' => CarbonImmutable::parse('2026-11-01')]));
        $off = giftCreate(giftFields($mug), active: false);

        expect(DB::table('promotions.gift_levels')->count())->toBe(6)
            ->and((bool) DB::table('promotions.gift_levels')->where('id', $off)->value('active'))->toBeFalse();

        // Another store's level never clashes.
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::GIFT_MANAGE], ['eg']));
        [$egPen] = gifts();
        expect(giftCreate(giftFields($egPen), 'eg'))->toBeString();
    });

    it('checks a clash again when a level is switched on, and when an active one is changed', function () {
        giftManager();
        [$pen, $mug] = gifts();
        giftCreate(giftFields($pen));
        $off = giftCreate(giftFields($mug), active: false);
        $other = giftCreate(giftFields($mug, 2000_00));

        expect(fn () => app(SwitchGiftLevelHandler::class)->handle(new SwitchGiftLevel($off, true)))->toThrow(GiftLevelClash::class)
            ->and(fn () => app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($other, giftFields($mug))))->toThrow(GiftLevelClash::class)
            ->and(DB::table('promotions.gift_levels')->where('id', $other)->value('amount_minor'))->toBe(2000_00);

        // Off, it may take the amount; and an active level never clashes with itself.
        app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($off, giftFields($pen)));
        app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($other, giftFields($pen, 2000_00)));

        expect(DB::table('promotions.gift_levels')->where('id', $off)->value('variant_id'))->toBe($pen)
            ->and(DB::table('promotions.gift_levels')->where('id', $other)->value('variant_id'))->toBe($pen);
    });
});

describe('changing and switching', function () {
    it('changes a level for every later order and audits only what changed', function () {
        giftManager();
        [$pen, $mug] = gifts();
        $id = giftCreate(giftFields($pen));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($id, giftFields($mug, 1500_00)));
        $audit = giftAudit('promotions.gift_level.updated');

        expect(DB::table('promotions.gift_levels')->where('id', $id)->value('variant_id'))->toBe($mug)
            ->and(DB::table('promotions.gift_levels')->where('id', $id)->value('amount_minor'))->toBe(1500_00)
            ->and(array_keys($audit['changes']))->toEqualCanonicalizing(['amount_minor', 'variant_id'])
            ->and($audit['changes']['amount_minor'])->toBe([1000_00, 1500_00]);

        // The same again: nothing written, nothing audited.
        $updatedAt = DB::table('promotions.gift_levels')->where('id', $id)->value('updated_at');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($id, giftFields($mug, 1500_00)));
        CarbonImmutable::setTestNow();

        expect(DB::table('platform.audit_entries')->where('action', 'promotions.gift_level.updated')->count())->toBe(1)
            ->and(DB::table('promotions.gift_levels')->where('id', $id)->value('updated_at'))->toBe($updatedAt);
    });

    it('checks only a new gift: one archived since never blocks changing the dates', function () {
        giftManager();
        // A third size: a ready product keeps one variant at least.
        [$pen, $mug] = Px::ready(['60 cm', '80 cm', '100 cm'])['variants'];
        $id = giftCreate(giftFields($pen));
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($pen)));
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($mug)));

        app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($id, giftFields($pen, with: ['endsAt' => CarbonImmutable::parse('2027-01-01')])));

        expect(DB::table('promotions.gift_levels')->where('id', $id)->value('ends_at'))->not->toBeNull()
            ->and(Pr::thrown(fn () => app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($id, giftFields($mug))), InvalidPromotionAttribute::class)->attribute)->toBe('gift_variant');
    });

    it('switches a level off and on, audited, and does nothing when it already is', function () {
        giftManager();
        [$pen] = gifts();
        $id = giftCreate(giftFields($pen));

        app(SwitchGiftLevelHandler::class)->handle(new SwitchGiftLevel($id, true));
        expect(DB::table('platform.audit_entries')->where('action', 'like', 'promotions.gift_level.%activated')->exists())->toBeFalse();

        app(SwitchGiftLevelHandler::class)->handle(new SwitchGiftLevel($id, false));
        expect((bool) DB::table('promotions.gift_levels')->where('id', $id)->value('active'))->toBeFalse()
            ->and(giftAudit('promotions.gift_level.deactivated')['changes'])->toBe(['active' => [true, false]]);

        app(SwitchGiftLevelHandler::class)->handle(new SwitchGiftLevel($id, true));
        expect((bool) DB::table('promotions.gift_levels')->where('id', $id)->value('active'))->toBeTrue()
            ->and(giftAudit('promotions.gift_level.activated')['changes'])->toBe(['active' => [false, true]]);
    });
});

describe('who may', function () {
    it('lets staff manage gift levels in their own stores only; nobody without the job', function () {
        giftManager(['sa']);
        [$pen] = gifts();

        expect(fn () => giftCreate(giftFields($pen), 'eg'))->toThrow(Unauthorized::class)
            ->and(fn () => app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel('not-a-store', giftFields($pen))))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE], ['sa']));
        expect(fn () => giftCreate(giftFields($pen)))->toThrow(Unauthorized::class)
            ->and(DB::table('promotions.gift_levels')->count())->toBe(0);
    });

    it('answers a level of a store the staff member does not cover as not found, as an unknown one', function (Closure $write) {
        [$pen] = gifts();
        $egypt = Pr::giftLevel($pen, 1000_00, 'eg');
        giftManager(['sa']);

        expect(fn () => $write($egypt, $pen))->toThrow(GiftLevelNotFound::class)
            ->and(fn () => $write(Pr::orderId(), $pen))->toThrow(GiftLevelNotFound::class)
            ->and(fn () => $write('not-an-id', $pen))->toThrow(GiftLevelNotFound::class);

        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE], ['*']));
        expect(fn () => $write($egypt, $pen))->toThrow(Unauthorized::class);
    })->with([
        'changing' => [fn (string $id, string $pen) => app(ChangeGiftLevelHandler::class)->handle(new ChangeGiftLevel($id, giftFields($pen)))],
        'switching' => [fn (string $id) => app(SwitchGiftLevelHandler::class)->handle(new SwitchGiftLevel($id, false))],
    ]);

    it('keeps a switched-off store\'s gift levels for Super Admins alone (§1.8)', function () {
        giftManager(['*']);
        [$pen] = gifts();
        $eg = Fx::storeId('eg');
        $egypt = giftCreate(giftFields($pen), 'eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $inEgypt = static fn (int $amount): string => app(CreateGiftLevelHandler::class)->handle(new CreateGiftLevel($eg, giftFields($pen, $amount)));

        expect(fn () => $inEgypt(2000_00))->toThrow(Unauthorized::class)
            ->and(fn () => app(SwitchGiftLevelHandler::class)->handle(new SwitchGiftLevel($egypt, false)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $inEgypt(2000_00);
        app(SwitchGiftLevelHandler::class)->handle(new SwitchGiftLevel($egypt, false));

        expect(DB::table('promotions.gift_levels')->count())->toBe(2)
            ->and((bool) DB::table('promotions.gift_levels')->where('id', $egypt)->value('active'))->toBeFalse();
    });
});

describe('the levels an order reaches', function () {
    it('answers the levels reached now for the buyer, highest first, in the store\'s currency', function () {
        [$pen, $mug] = gifts();
        $now = CarbonImmutable::now();
        $thousand = Pr::giftLevel($pen, 1000_00);
        $twoThousand = Pr::giftLevel($mug, 2000_00);
        $companies = Pr::giftLevel($mug, 1500_00, with: ['for_public' => false]);
        Pr::giftLevel($mug, 500_00, with: ['active' => false]);
        Pr::giftLevel($mug, 600_00, with: ['starts_at' => $now->addMinute()]);
        Pr::giftLevel($mug, 700_00, with: ['ends_at' => $now]);
        Pr::giftLevel($mug, 800_00, 'eg');
        $started = Pr::giftLevel($pen, 900_00, with: ['starts_at' => $now->subMinute(), 'ends_at' => $now->addMinute()]);

        expect(reached(2000_00))->toBe([[$twoThousand, 2000_00, $mug], [$thousand, 1000_00, $pen], [$started, 900_00, $pen]])
            ->and(reached(1999_99))->toBe([[$thousand, 1000_00, $pen], [$started, 900_00, $pen]])
            ->and(reached(1999_99, Audience::Company))->toBe([[$companies, 1500_00, $mug], [$thousand, 1000_00, $pen], [$started, 900_00, $pen]])
            ->and(reached(899_99))->toBe([])
            ->and(reached(5000_00, storeCode: 'eg'))->toHaveCount(1);
    });

    it('refuses a goods total in another currency, and an unknown store', function () {
        expect(Pr::thrown(fn () => Pr::api()->giftLevels(StoreId::fromString(Fx::storeId('sa')), Audience::Public, Pr::money(1000_00, 'eg')), InvalidPromotionAttribute::class)->attribute)->toBe('currency')
            ->and(Pr::thrown(fn () => Pr::api()->giftLevels(StoreId::fromString(strtolower((string) Str::ulid())), Audience::Public, Pr::money(1000_00)), InvalidPromotionAttribute::class)->attribute)->toBe('store');
    });
});

describe('the table', function () {
    it('refuses what the code would never write', function (array $with, string $constraint) {
        [$pen] = gifts();

        expect(fn () => DB::transaction(fn () => Pr::giftLevel($pen, 1000_00, with: $with)))->toThrow(QueryException::class, "\"{$constraint}\"");
    })->with([
        'no amount' => [['amount_minor' => 0], 'gift_levels_amount'],
        'nobody to give it to' => [['for_public' => false, 'for_company' => false], 'gift_levels_audiences'],
        'the end at the start' => [['starts_at' => '2026-11-01 00:00:00+00', 'ends_at' => '2026-11-01 00:00:00+00'], 'gift_levels_dates'],
        'an unknown currency' => [['currency_code' => 'XXX'], 'gift_levels_currency'],
        'no such store' => [['store_id' => '01hzzzzzzzzzzzzzzzzzzzzzzz'], 'gift_levels_store'],
        'no such variant' => [['variant_id' => '01hzzzzzzzzzzzzzzzzzzzzzzz'], 'gift_levels_variant'],
    ]);

    it('takes a level with only one date of the two', function () {
        [$pen] = gifts();
        Pr::giftLevel($pen, 1000_00, with: ['ends_at' => '2026-11-01 00:00:00+00']);
        Pr::giftLevel($pen, 2000_00, with: ['starts_at' => '2026-11-01 00:00:00+00']);

        expect(DB::table('promotions.gift_levels')->count())->toBe(2);
    });
});
