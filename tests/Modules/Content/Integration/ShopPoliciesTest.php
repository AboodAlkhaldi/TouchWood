<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyInput;
use Modules\Content\Infrastructure\External\ContentShopPolicies;
use Modules\Platform\Public\Contracts\ShopPolicies;
use Modules\Platform\Public\Dto\ShopPolicyDto;
use Modules\Platform\Public\Enums\PolicyKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Build 4, step 2b (flags-2026-11.md S3): Content answers Platform's `ShopPolicies` in place of its stand-in - a store's
| published policy of a kind, or all of them in the kinds' order, in the language asked, with the number of its text
| (F14) and when that text was published (F15); a draft, another store's or an unpublished one is none. One query cold,
| the cache's two warm.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'ae', 'eg']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $with
 * @param  list<string>  $codes
 */
function shopPoliciesSave(array $with, array $codes = ['sa']): string
{
    static $n = 0;
    $kind = strtolower((string) ($with['kind'] ?? 'TERMS'));
    $n++;

    return app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(...[
        'id' => null,
        'kind' => 'TERMS',
        'storeIds' => array_map(static fn (string $code): string => Fx::storeId($code), $codes),
        'version' => 0,
        'titleAr' => "سياسة {$n}",
        'titleEn' => "Policy {$kind} {$n}",
        'bodyAr' => 'نص السياسة',
        'bodyEn' => 'The policy text',
        ...$with,
    ])));
}

function shopPoliciesPublish(string $id): void
{
    app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, (int) DB::table('content.policies')->where('id', $id)->value('version')));
}

/** @return list<string> */
function shopPoliciesKinds(string $code, string $locale = 'en'): array
{
    return array_map(static fn (ShopPolicyDto $policy): string => $policy->kind->value, app(ShopPolicies::class)->allPublished(StoreId::fromString(Fx::storeId($code)), $locale));
}

it('is Content\'s answer in place of Platform\'s stand-in, one per request', function () {
    expect(app(ShopPolicies::class))->toBeInstanceOf(ContentShopPolicies::class)
        ->and(app(ShopPolicies::class))->toBe(app(ShopPolicies::class));
});

it('answers a store\'s published policy of a kind in each language, never a draft', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 11:02:00', 'UTC'));
    $terms = shopPoliciesSave(['titleAr' => 'الشروط والأحكام', 'titleEn' => 'Terms and Conditions']);
    $draft = shopPoliciesSave(['kind' => 'PRIVACY']);
    $store = StoreId::fromString(Fx::storeId('sa'));

    expect(app(ShopPolicies::class)->published($store, PolicyKind::Terms, 'en'))->toBeNull();

    shopPoliciesPublish($terms);
    $en = app(ShopPolicies::class)->published($store, PolicyKind::Terms, 'en');
    $ar = app(ShopPolicies::class)->published($store, PolicyKind::Terms, 'ar');

    expect($en?->policyId)->toBe($terms)
        ->and($en?->kind)->toBe(PolicyKind::Terms)
        ->and($en?->version)->toBe(1)
        ->and($en?->title)->toBe('Terms and Conditions')
        ->and($en?->slug)->toBe('terms-and-conditions')
        ->and($en?->updatedAt->format(DATE_ATOM))->toBe('2026-11-18T11:02:00+00:00')
        ->and($ar?->title)->toBe('الشروط والأحكام')
        ->and($ar?->slug)->toBe('الشروط-والأحكام')
        ->and(app(ShopPolicies::class)->published($store, PolicyKind::Privacy, 'en'))->toBeNull()
        ->and(DB::table('content.policies')->where('id', $draft)->value('status'))->toBe('DRAFT');
});

it('answers only the stores a policy serves, and none once unpublished', function () {
    $terms = shopPoliciesSave([], ['sa', 'ae']);
    shopPoliciesPublish($terms);

    expect(app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('ae')), PolicyKind::Terms, 'en')?->policyId)->toBe($terms)
        ->and(app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('eg')), PolicyKind::Terms, 'en'))->toBeNull();

    app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($terms, (int) DB::table('content.policies')->where('id', $terms)->value('version')));

    expect(app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('sa')), PolicyKind::Terms, 'en'))->toBeNull()
        ->and(shopPoliciesKinds('sa'))->toBe([]);
});

it('lists every published policy in the kinds\' order, the Other ones by when they were made', function () {
    // Made out of order: Other twice, then Returns, Terms, Privacy.
    $firstOther = shopPoliciesSave(['kind' => 'OTHER', 'titleEn' => 'Cookies', 'titleAr' => 'الكوكيز']);
    $secondOther = shopPoliciesSave(['kind' => 'OTHER', 'titleEn' => 'Accessibility', 'titleAr' => 'سهولة الوصول']);

    foreach ([$firstOther, $secondOther, shopPoliciesSave(['kind' => 'RETURNS']), shopPoliciesSave(['kind' => 'TERMS']), shopPoliciesSave(['kind' => 'PRIVACY'])] as $policy) {
        shopPoliciesPublish($policy);
    }

    expect(shopPoliciesKinds('sa'))->toBe(['TERMS', 'PRIVACY', 'RETURNS', 'OTHER', 'OTHER'])
        ->and(array_map(static fn (ShopPolicyDto $policy): string => $policy->title, array_slice(app(ShopPolicies::class)->allPublished(StoreId::fromString(Fx::storeId('sa')), 'en'), 3)))->toBe(['Cookies', 'Accessibility'])
        // Asked for Other, the first in the store's order: the one made first.
        ->and(app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('sa')), PolicyKind::Other, 'en')?->policyId)->toBe($firstOther);
});

it('raises the version with a changed publish, and "Last updated" is when that text was published', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));
    $words = ['titleAr' => 'الشروط', 'titleEn' => 'Terms'];
    $terms = shopPoliciesSave($words);
    shopPoliciesPublish($terms);
    $store = StoreId::fromString(Fx::storeId('sa'));

    // Saved with the same words a day later, its search title only changed: the same version, the same moment.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    shopPoliciesSave([...$words, 'id' => $terms, 'version' => 2, 'seoTitleAr' => 'الشروط', 'seoTitleEn' => 'Terms']);
    $same = app(ShopPolicies::class)->published($store, PolicyKind::Terms, 'en');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 09:30:00', 'UTC'));
    shopPoliciesSave([...$words, 'id' => $terms, 'version' => 3, 'bodyEn' => 'The new terms', 'seoTitleAr' => 'الشروط', 'seoTitleEn' => 'Terms']);
    $changed = app(ShopPolicies::class)->published($store, PolicyKind::Terms, 'en');

    expect($same?->version)->toBe(1)
        ->and($same?->updatedAt->format(DATE_ATOM))->toBe('2026-11-01T08:00:00+00:00')
        ->and($changed?->version)->toBe(2)
        ->and($changed?->updatedAt->format(DATE_ATOM))->toBe('2026-11-03T09:30:00+00:00');
});

it('answers a split copy for its own store at version 1, the original for the others', function () {
    $words = ['titleAr' => 'الشروط', 'titleEn' => 'Terms'];
    $shared = shopPoliciesSave($words, ['sa', 'eg']);
    shopPoliciesPublish($shared);
    shopPoliciesSave([...$words, 'id' => $shared, 'version' => 2, 'bodyEn' => 'Changed', 'bodyAr' => 'نص آخر'], ['sa']);
    $copy = (string) DB::table('content.policy_stores')->where('store_id', Fx::storeId('sa'))->value('policy_id');
    $sa = app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('sa')), PolicyKind::Terms, 'en');
    $eg = app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('eg')), PolicyKind::Terms, 'en');

    expect($copy)->not->toBe($shared)
        ->and($sa?->policyId)->toBe($copy)
        ->and($sa?->version)->toBe(1)
        ->and($eg?->policyId)->toBe($shared)
        ->and($eg?->version)->toBe(1)
        // Both keep the address: unique per store (owner, 2026-11-20).
        ->and($sa?->slug)->toBe($eg?->slug);
});

it('reads in one query cold and only the cache\'s two warm', function () {
    shopPoliciesPublish(shopPoliciesSave([]));
    $store = StoreId::fromString(Fx::storeId('sa'));
    /** @return list<string> */
    $count = function (callable $read): array {
        /** @var ArrayObject<int, string> $queries */
        $queries = new ArrayObject;
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries[] = $query->sql;
        });
        $read();

        return array_values($queries->getArrayCopy());
    };

    $cold = $count(fn () => app(ShopPolicies::class)->allPublished($store, 'en'));
    app()->forgetScopedInstances();
    $warm = $count(fn () => app(ShopPolicies::class)->published($store, PolicyKind::Terms, 'ar'));

    expect(count(array_filter($cold, static fn (string $sql): bool => str_contains($sql, 'content.polic'))))->toBe(1)
        ->and(array_values(array_filter($warm, static fn (string $sql): bool => str_contains($sql, 'content.'))))->toBe([])
        ->and(count($warm))->toBe(2);
});
