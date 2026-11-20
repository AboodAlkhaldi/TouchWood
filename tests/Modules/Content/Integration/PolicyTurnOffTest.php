<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Content\Application\Command\DeletePolicy\DeletePolicy;
use Modules\Content\Application\Command\DeletePolicy\DeletePolicyHandler;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyInput;
use Modules\Content\Application\Policies\PolicyWriter;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\PolicyNotDeletable;
use Modules\Content\Domain\Exception\SlugTaken;
use Modules\Content\Domain\Exception\StaleEdit;
use Modules\Content\Domain\Model\PolicyKind;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\Policies;
use Modules\Platform\Application\Policies\NoShopPolicies;
use Modules\Platform\Public\Contracts\ShopPolicies;
use Modules\Platform\Public\Enums\PolicyKind as ShopPolicyKind;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The owner's decisions of 2026-11-20 on policies: Unpublish acts per store, as a save does - a person picks the stores
| they hold; all of them unpublishes the whole policy; some of them (option A) take those stores out into a switched-off
| copy of their own, a draft with the same words, its addresses with it, while the others keep the published policy.
| Delete acts per store too, on a policy never published. What is in force in each store is recorded, version by version,
| from when to when (`content.policy_periods`). And the builder's pick: an address a policy switched off in a store holds
| there is free for another policy. Every helper here is named after this file's subject: a Pest file's functions are
| global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  array<string, mixed>  $with
 * @param  list<string>  $codes
 */
function policyTurnOffSave(array $with = [], array $codes = ['sa', 'eg']): string
{
    return app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(...[
        'id' => null,
        'kind' => 'TERMS',
        'storeIds' => array_map(static fn (string $code): string => Fx::storeId($code), $codes),
        'version' => 0,
        'titleAr' => 'الشروط والأحكام',
        'titleEn' => 'Terms and Conditions',
        'slugAr' => 'الشروط',
        'slugEn' => 'terms',
        'bodyAr' => 'نص الشروط',
        'bodyEn' => 'The terms',
        ...$with,
    ])));
}

function policyTurnOffVersion(string $id): int
{
    return (int) DB::table('content.policies')->where('id', $id)->value('version');
}

function policyTurnOffPublish(string $id): void
{
    app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, policyTurnOffVersion($id)));
}

/**
 * @param  list<string>|null  $codes
 */
function policyTurnOffOff(string $id, ?array $codes): ?string
{
    return app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($id, policyTurnOffVersion($id), $codes === null ? null : array_map(static fn (string $code): string => Fx::storeId($code), $codes)));
}

/**
 * @param  list<string>|null  $codes
 */
function policyTurnOffDelete(string $id, ?array $codes): void
{
    app(DeletePolicyHandler::class)->handle(new DeletePolicy($id, policyTurnOffVersion($id), $codes === null ? null : array_map(static fn (string $code): string => Fx::storeId($code), $codes)));
}

/** @return list<string> the stores' ids, in id order */
function policyTurnOffStores(string ...$codes): array
{
    $ids = array_map(static fn (string $code): string => Fx::storeId($code), $codes);
    sort($ids);

    return $ids;
}

/**
 * Each period of a policy, by store code: `number from..until`, the moments as the clock gave them.
 *
 * @return array<string, list<string>>
 */
function policyTurnOffPeriods(string $id): array
{
    $codes = [];

    foreach (['sa', 'eg', 'ae'] as $code) {
        $codes[Fx::storeId($code)] = $code;
    }

    $periods = [];

    foreach (DB::table('content.policy_periods')->where('policy_id', $id)->orderBy('started_at')->orderBy('number')->get() as $row) {
        $from = CarbonImmutable::parse((string) $row->started_at)->utc()->format('H:i');
        $until = $row->ended_at === null ? 'open' : CarbonImmutable::parse((string) $row->ended_at)->utc()->format('H:i');
        $periods[$codes[(string) $row->store_id]][] = "{$row->number} {$from}..{$until} {$row->slug_en}";
    }

    ksort($periods);

    return $periods;
}

/** @return array<string, string> each policy address in a store, `locale:slug` => the policy holding it there */
function policyTurnOffAddresses(string $code): array
{
    $held = [];

    foreach (DB::table('content.slug_history')->where('kind', 'POLICY')->where('store_id', Fx::storeId($code))->orderBy('locale')->orderBy('slug')->get() as $row) {
        $held["{$row->locale}:{$row->slug}"] = (string) $row->item_id;
    }

    return $held;
}

/** Moves the clock to this hour of the frozen day (UTC). */
function policyTurnOffAt(string $time): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse("2026-11-11 {$time}:00+00"));
}

describe('Unpublish in some of its stores (owner, 2026-11-20: option A)', function () {
    it('hands the store turned off its old addresses too, so they still lead to its own policy there', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        policyTurnOffSave(['id' => $shared, 'version' => policyTurnOffVersion($shared), 'slugEn' => 'conditions']);

        $copy = policyTurnOffOff($shared, ['sa']);

        expect(policyTurnOffAddresses('sa'))->toBe(['ar:الشروط' => $copy, 'en:conditions' => $copy, 'en:terms' => $copy])
            ->and(policyTurnOffAddresses('eg'))->toBe(['ar:الشروط' => $shared, 'en:conditions' => $shared, 'en:terms' => $shared]);
    });

    it('lets Ahmed, who holds KSA alone, turn KSA off: KSA leaves into a switched-off copy of its own, Egypt keeps the published policy untouched', function () {
        policyTurnOffAt('08:00');
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        policyTurnOffAt('10:00');
        $ahmed = Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);

        $copy = policyTurnOffOff($shared, ['sa']);
        $kept = app(Policies::class)->find((string) $copy);
        $original = app(Policies::class)->find($shared) ?? throw new LogicException('No original.');
        $turned = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.policy.turned_off')->where('subject_id', $shared)->value('changes'), true);
        $created = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.policy.created')->where('subject_id', $copy)->value('changes'), true);

        expect($copy)->toBeString()
            ->and($copy)->not->toBe($shared)
            // The copy: a draft of the kind, the same words and addresses, KSA alone, its own versions to come from 1.
            ->and($kept?->kind)->toBe(PolicyKind::Terms)
            ->and($kept?->stores)->toBe([Fx::storeId('sa')])
            ->and($kept?->status)->toBe(Publication::Draft)
            ->and([$kept?->textVersion, $kept?->version, $kept?->updatedBy])->toBe([0, 1, $ahmed])
            ->and($kept?->sameContentAs($original))->toBeTrue()
            ->and(DB::table('content.policy_versions')->where('policy_id', $copy)->count())->toBe(0)
            // The original: still published in Egypt, its text and its version as they were.
            ->and($original->stores)->toBe([Fx::storeId('eg')])
            ->and($original->status)->toBe(Publication::Published)
            ->and($original->textVersion)->toBe(1)
            ->and(DB::table('content.policy_versions')->where('policy_id', $shared)->pluck('number')->all())->toBe([1])
            // KSA's addresses went with KSA; Egypt's stayed.
            ->and(policyTurnOffAddresses('sa'))->toBe(['ar:الشروط' => $copy, 'en:terms' => $copy])
            ->and(policyTurnOffAddresses('eg'))->toBe(['ar:الشروط' => $shared, 'en:terms' => $shared])
            // In force in KSA until now; still in Egypt.
            ->and(policyTurnOffPeriods($shared))->toBe(['eg' => ['1 08:00..open terms'], 'sa' => ['1 08:00..10:00 terms']])
            ->and($turned['turned_off_to'] ?? null)->toBe([null, $copy])
            ->and($turned['stores'] ?? null)->toBe([implode(',', policyTurnOffStores('sa', 'eg')), Fx::storeId('eg')])
            ->and($created['turned_off_from'] ?? null)->toBe([null, $shared])
            // The shop: KSA has no Terms now, Egypt still has them.
            ->and(app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('sa')), ShopPolicyKind::Terms, 'en'))->toBeNull()
            ->and(app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('eg')), ShopPolicyKind::Terms, 'en')?->policyId)->toBe($shared);
    });

    it('refuses Ahmed turning Egypt off, a store he does not hold, changing nothing', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);

        expect(fn () => policyTurnOffOff($shared, ['eg']))->toThrow(Unauthorized::class)
            ->and(fn () => policyTurnOffOff($shared, ['sa', 'eg']))->toThrow(Unauthorized::class)
            // None named is every store it serves: Egypt among them.
            ->and(fn () => policyTurnOffOff($shared, null))->toThrow(Unauthorized::class)
            ->and(DB::table('content.policies')->count())->toBe(1)
            ->and(app(Policies::class)->find($shared)?->stores)->toBe(policyTurnOffStores('sa', 'eg'))
            ->and(DB::table('content.policies')->where('id', $shared)->value('status'))->toBe('PUBLISHED');
    });

    it('lets Sara, who holds both, turn off one store or both; both is the whole policy unpublished, no copy made', function () {
        policyTurnOffAt('08:00');
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $one = policyTurnOffSave();
        policyTurnOffPublish($one);
        $both = policyTurnOffSave(['kind' => 'PRIVACY', 'titleAr' => 'الخصوصية', 'titleEn' => 'Privacy', 'slugAr' => 'الخصوصية', 'slugEn' => 'privacy']);
        policyTurnOffPublish($both);
        policyTurnOffAt('09:00');

        $egypt = policyTurnOffOff($one, ['eg']);

        expect(app(Policies::class)->find((string) $egypt)?->stores)->toBe([Fx::storeId('eg')])
            ->and(app(Policies::class)->find($one)?->stores)->toBe([Fx::storeId('sa')])
            ->and(policyTurnOffOff($both, ['sa', 'eg']))->toBeNull()
            ->and(DB::table('content.policies')->count())->toBe(3)
            ->and(DB::table('content.policies')->where('id', $both)->value('status'))->toBe('DRAFT')
            ->and(app(Policies::class)->find($both)?->stores)->toBe(policyTurnOffStores('sa', 'eg'))
            ->and(policyTurnOffPeriods($both))->toBe(['eg' => ['1 08:00..09:00 privacy'], 'sa' => ['1 08:00..09:00 privacy']])
            ->and(Fx::audits('content.policy.unpublished', $both))->toBe(1)
            ->and(Fx::audits('content.policy.turned_off', $both))->toBe(0);
    });

    it('lets an Egypt-only editor turn off Egypt alone', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['eg']);

        $copy = policyTurnOffOff($shared, ['eg']);

        expect(app(Policies::class)->find((string) $copy)?->stores)->toBe([Fx::storeId('eg')])
            ->and(app(Policies::class)->find($shared)?->stores)->toBe([Fx::storeId('sa')])
            // The policy left behind serves KSA alone now, no store of theirs: not found, as any such.
            ->and(fn () => policyTurnOffOff($shared, ['sa']))->toThrow(ContentNotFound::class);
    });

    it('refuses no store, or a store the policy does not serve, and an old version of the form', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg', 'ae']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        $refusal = static function (callable $work): string {
            try {
                $work();
            } catch (InvalidContentInput $refused) {
                return "{$refused->attribute}:{$refused->rule}";
            }

            return 'not refused';
        };

        expect($refusal(fn () => policyTurnOffOff($shared, [])))->toBe('stores:too_few')
            // The writer itself refuses none, whoever calls it.
            ->and($refusal(fn () => app(PolicyWriter::class)->unpublish($shared, policyTurnOffVersion($shared), [], null)))->toBe('stores:choice')
            ->and($refusal(fn () => app(PolicyWriter::class)->delete($shared, policyTurnOffVersion($shared), [])))->toBe('stores:choice')
            ->and($refusal(fn () => policyTurnOffOff($shared, ['ae'])))->toBe('stores:choice')
            ->and($refusal(fn () => policyTurnOffOff($shared, ['sa', 'ae'])))->toBe('stores:choice')
            ->and($refusal(fn () => policyTurnOffDelete(policyTurnOffSave(['kind' => 'OTHER', 'titleAr' => 'أخرى', 'titleEn' => 'Other', 'slugAr' => 'أخرى', 'slugEn' => 'other']), ['ae'])))->toBe('stores:choice')
            ->and(fn () => app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($shared, policyTurnOffVersion($shared) - 1, [Fx::storeId('sa')])))->toThrow(StaleEdit::class)
            ->and(DB::table('content.policies')->where('kind', 'TERMS')->count())->toBe(1)
            ->and(app(Policies::class)->find($shared)?->stores)->toBe(policyTurnOffStores('sa', 'eg'));
    });

    it('leaves a draft as it is', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $draft = policyTurnOffSave();

        expect(policyTurnOffOff($draft, ['sa']))->toBeNull()
            ->and(DB::table('content.policies')->count())->toBe(1)
            ->and(app(Policies::class)->find($draft)?->stores)->toBe(policyTurnOffStores('sa', 'eg'))
            ->and(policyTurnOffVersion($draft))->toBe(1);
    });

    it('publishes the switched-off copy again at its own version 1, in force in KSA from then', function () {
        policyTurnOffAt('08:00');
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        policyTurnOffAt('09:00');
        $copy = (string) policyTurnOffOff($shared, ['sa']);
        policyTurnOffAt('11:00');
        policyTurnOffPublish($copy);

        expect(DB::table('content.policies')->where('id', $copy)->value('status'))->toBe('PUBLISHED')
            ->and((int) DB::table('content.policies')->where('id', $copy)->value('text_version'))->toBe(1)
            ->and(policyTurnOffPeriods($copy))->toBe(['sa' => ['1 11:00..open terms']])
            ->and(policyTurnOffPeriods($shared))->toBe(['eg' => ['1 08:00..open terms'], 'sa' => ['1 08:00..09:00 terms']])
            ->and(app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('sa')), ShopPolicyKind::Terms, 'en')?->policyId)->toBe($copy);
    });
});

describe('Delete in some of its stores (owner, 2026-11-20)', function () {
    it('takes the stores picked off a draft, freeing their addresses there; all of them deletes it', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $draft = policyTurnOffSave();
        $ahmed = Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);

        expect(fn () => policyTurnOffDelete($draft, ['eg']))->toThrow(Unauthorized::class);

        policyTurnOffDelete($draft, ['sa']);

        expect(app(Policies::class)->find($draft)?->stores)->toBe([Fx::storeId('eg')])
            ->and(app(Policies::class)->find($draft)?->updatedBy)->toBe($ahmed)
            ->and(policyTurnOffAddresses('sa'))->toBe([])
            ->and(policyTurnOffAddresses('eg'))->toBe(['ar:الشروط' => $draft, 'en:terms' => $draft])
            ->and(Fx::audits('content.policy.deleted_in', $draft))->toBe(1)
            ->and(Fx::audits('content.policy.deleted', $draft))->toBe(0);

        Cx::editor([ContentPermissions::POLICY_MANAGE], ['eg']);
        policyTurnOffDelete($draft, ['eg']);

        expect(DB::table('content.policies')->where('id', $draft)->exists())->toBeFalse()
            ->and(policyTurnOffAddresses('eg'))->toBe([])
            ->and(Fx::audits('content.policy.deleted', $draft))->toBe(1);
    });

    it('refuses to delete a policy once published in any of its stores, keeping it whole', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        $copy = (string) policyTurnOffOff($shared, ['sa']);
        policyTurnOffOff($shared, ['eg']);

        expect(fn () => policyTurnOffDelete($shared, ['eg']))->toThrow(PolicyNotDeletable::class)
            ->and(app(Policies::class)->find($shared)?->stores)->toBe([Fx::storeId('eg')])
            // The switched-off copy was never published: a draft, deleted as one.
            ->and(fn () => policyTurnOffDelete($copy, ['sa']))->not->toThrow(PolicyNotDeletable::class)
            ->and(DB::table('content.policies')->where('id', $copy)->exists())->toBeFalse();
    });
});

describe('what is in force in each store, from when to when (owner, 2026-11-20)', function () {
    it('opens a period per store at a publish; a new version, a store added or taken off, an address changed and Unpublish close or open them', function () {
        policyTurnOffAt('08:00');
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg', 'ae']);
        $policy = policyTurnOffSave();
        // A draft is in force nowhere.
        expect(policyTurnOffPeriods($policy))->toBe([]);
        policyTurnOffPublish($policy);
        policyTurnOffAt('09:00');
        // Its words changed in every store: version 2.
        policyTurnOffSave(['id' => $policy, 'version' => policyTurnOffVersion($policy), 'bodyEn' => 'New terms'], ['sa', 'eg']);
        policyTurnOffAt('10:00');
        // The UAE added: version 2 there from now.
        policyTurnOffSave(['id' => $policy, 'version' => policyTurnOffVersion($policy), 'bodyEn' => 'New terms'], ['sa', 'eg', 'ae']);
        policyTurnOffAt('11:00');
        // Egypt taken off by a save, the words unchanged.
        policyTurnOffSave(['id' => $policy, 'version' => policyTurnOffVersion($policy), 'bodyEn' => 'New terms'], ['sa', 'ae']);
        policyTurnOffAt('12:00');
        // A new address, the words unchanged: the same version, read at its new address from now.
        policyTurnOffSave(['id' => $policy, 'version' => policyTurnOffVersion($policy), 'bodyEn' => 'New terms', 'slugEn' => 'terms-of-sale'], ['sa', 'ae']);
        policyTurnOffAt('13:00');
        policyTurnOffOff($policy, null);
        policyTurnOffAt('14:00');
        policyTurnOffPublish($policy);

        expect(policyTurnOffPeriods($policy))->toBe([
            'ae' => ['2 10:00..12:00 terms', '2 12:00..13:00 terms-of-sale', '2 14:00..open terms-of-sale'],
            'eg' => ['1 08:00..09:00 terms', '2 09:00..11:00 terms'],
            'sa' => ['1 08:00..09:00 terms', '2 09:00..12:00 terms', '2 12:00..13:00 terms-of-sale', '2 14:00..open terms-of-sale'],
        ]);
    });

    it('ends the periods of the stores a split moves at the split, the copy\'s version 1 starting with it', function () {
        policyTurnOffAt('08:00');
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        policyTurnOffAt('09:00');
        $ksa = policyTurnOffSave(['id' => $shared, 'version' => policyTurnOffVersion($shared), 'bodyEn' => 'KSA terms'], ['sa']);

        expect(policyTurnOffPeriods($shared))->toBe(['eg' => ['1 08:00..open terms'], 'sa' => ['1 08:00..09:00 terms']])
            ->and(policyTurnOffPeriods($ksa))->toBe(['sa' => ['1 09:00..open terms']]);
    });

    it('fills the periods of policies published before the table, from what is known', function () {
        policyTurnOffAt('08:00');
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $live = policyTurnOffSave();
        policyTurnOffPublish($live);
        $off = policyTurnOffSave(['kind' => 'PRIVACY', 'titleAr' => 'الخصوصية', 'titleEn' => 'Privacy', 'slugAr' => 'الخصوصية', 'slugEn' => 'privacy'], ['sa']);
        policyTurnOffPublish($off);
        policyTurnOffAt('09:00');
        policyTurnOffSave(['id' => $live, 'version' => policyTurnOffVersion($live), 'bodyEn' => 'New terms']);
        policyTurnOffAt('10:00');
        policyTurnOffOff($off, null);
        $migration = require base_path('src/Modules/Content/Infrastructure/Persistence/Migrations/2026_11_21_100000_create_policy_periods_table.php');

        $migration->down();
        expect(Schema::hasTable('content.policy_periods'))->toBeFalse();
        $migration->up();

        expect(policyTurnOffPeriods($live))->toBe(['eg' => ['1 08:00..09:00 terms', '2 09:00..open terms'], 'sa' => ['1 08:00..09:00 terms', '2 09:00..open terms']])
            ->and(policyTurnOffPeriods($off))->toBe(['sa' => ['1 08:00..10:00 privacy']]);
    });
});

describe('an address held by a policy switched off in a store (the builder\'s pick, 2026-11-21)', function () {
    it('is taken by a new policy saved with it; the old one published again there is refused it', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);
        $old = policyTurnOffSave([], ['sa']);
        policyTurnOffPublish($old);
        policyTurnOffOff($old, null);

        $new = policyTurnOffSave(['titleEn' => 'New terms'], ['sa']);

        expect(policyTurnOffAddresses('sa'))->toBe(['ar:الشروط' => $new, 'en:terms' => $new])
            ->and(fn () => policyTurnOffPublish($old))->toThrow(SlugTaken::class)
            ->and(DB::table('content.policies')->where('id', $old)->value('status'))->toBe('DRAFT');

        policyTurnOffPublish($new);

        expect(DB::table('content.policies')->where('id', $new)->value('status'))->toBe('PUBLISHED');
    });

    it('stays with a policy live in that store, and with a draft never published (a switched-off copy among them)', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $live = policyTurnOffSave([], ['sa']);
        policyTurnOffPublish($live);

        expect(fn () => policyTurnOffSave(['kind' => 'OTHER'], ['sa']))->toThrow(SlugTaken::class);

        $shared = policyTurnOffSave(['kind' => 'PRIVACY', 'slugAr' => 'خصوصية', 'slugEn' => 'privacy']);
        policyTurnOffPublish($shared);
        $copy = (string) policyTurnOffOff($shared, ['eg']);

        expect(fn () => policyTurnOffSave(['kind' => 'OTHER', 'slugAr' => 'أخرى', 'slugEn' => 'privacy'], ['eg']))->toThrow(SlugTaken::class)
            ->and(policyTurnOffAddresses('eg'))->toBe(['ar:خصوصية' => $copy, 'en:privacy' => $copy]);
    });
});

describe('where a kept version reads, for the staff\'s order page (ShopPolicies::versionAddresses)', function () {
    it('gives each version ever in force in the store its last address there, in the language asked, switched off or not; none for one never in force there', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policyTurnOffSave();
        policyTurnOffPublish($shared);
        // Version 1 read at a second address while in force: the last one is where it reads.
        policyTurnOffAt('09:00');
        policyTurnOffSave(['id' => $shared, 'version' => policyTurnOffVersion($shared), 'slugEn' => 'conditions']);
        policyTurnOffAt('10:00');
        policyTurnOffSave(['id' => $shared, 'version' => policyTurnOffVersion($shared), 'bodyEn' => 'New terms', 'slugEn' => 'terms-of-sale']);
        policyTurnOffOff($shared, ['sa']);
        $ksa = StoreId::fromString(Fx::storeId('sa'));
        $policies = app(ShopPolicies::class);

        expect($policies->versionAddresses($ksa, [[$shared, 1], [$shared, 2], [$shared, 3]], 'en'))->toBe(["{$shared}:1" => 'conditions', "{$shared}:2" => 'terms-of-sale'])
            ->and($policies->versionAddresses($ksa, [[$shared, 2]], 'ar'))->toBe(["{$shared}:2" => 'الشروط'])
            ->and($policies->versionAddresses(StoreId::fromString(Fx::storeId('ae')), [[$shared, 1]], 'en'))->toBe([])
            ->and($policies->versionAddresses($ksa, [], 'en'))->toBe([])
            ->and((new NoShopPolicies)->versionAddresses($ksa, [[$shared, 1]], 'en'))->toBe([]);
    });
});
