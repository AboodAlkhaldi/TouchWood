<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
use Modules\Content\Application\Text\MarkText;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\PolicyKindTaken;
use Modules\Content\Domain\Exception\PolicyNotDeletable;
use Modules\Content\Domain\Exception\SlugTaken;
use Modules\Content\Domain\Exception\StaleEdit;
use Modules\Content\Domain\Exception\StoreOff;
use Modules\Content\Domain\Model\PolicyKind;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\Policies;
use Modules\Content\Domain\ValueObject\RichText;
use Modules\Content\Infrastructure\Media\ContentMediaUsage;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Domain\Exception\MediaInUse;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| flags-2026-11.md S3 (build 4, step 1): a policy - its kind from the fixed list, its list of stores, its words per
| language as a page's - saved, published, unpublished and deleted under content.policy.manage in every store it
| serves; one published policy of a kind per store (Other excepted); each published text kept as a version (F14); an
| address unique among policies per language; audited.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  array<string, mixed>  $with
 * @param  list<string>  $codes
 */
function policiesInput(array $with = [], array $codes = ['sa']): PolicyInput
{
    return new PolicyInput(...[
        'id' => null,
        'kind' => 'TERMS',
        'storeIds' => array_map(static fn (string $code): string => Fx::storeId($code), $codes),
        'version' => 0,
        'titleAr' => 'الشروط والأحكام',
        'titleEn' => 'Terms and Conditions',
        'bodyAr' => 'نص الشروط',
        'bodyEn' => 'The terms',
        ...$with,
    ]);
}

/**
 * @param  array<string, mixed>  $with  PolicyInput's arguments, the policy's id and version among them
 * @param  list<string>  $codes
 */
function policiesSave(array $with, array $codes = ['sa']): string
{
    return app(SavePolicyHandler::class)->handle(new SavePolicy(policiesInput($with, $codes)));
}

function policiesVersion(string $id): int
{
    return (int) DB::table('content.policies')->where('id', $id)->value('version');
}

function policiesTextVersion(string $id): int
{
    return (int) DB::table('content.policies')->where('id', $id)->value('text_version');
}

function policiesPublish(string $id): void
{
    app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, policiesVersion($id)));
}

/** Deletes the file as a Super Admin, through Platform's own use case (its files on fake disks); the caller acts again as whom it needs. */
function policiesMediaDelete(string $mediaId): void
{
    Storage::fake('local');
    Storage::fake('public');
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(DeleteMediaHandler::class)->handle(new DeleteMedia($mediaId));
}

function policiesRefusal(callable $work): string
{
    try {
        $work();
    } catch (InvalidContentInput $refused) {
        return "{$refused->attribute}:{$refused->rule}";
    } catch (MissingEnglish $missing) {
        return "{$missing->attribute}:english";
    }

    throw new LogicException('Not refused.');
}

describe('saving', function () {
    it('keeps a kind, its stores and a title, address, body and SEO per language, Draft until published', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'ae']);
        $policy = policiesSave(['bodyAr' => "# الشروط\n\nتسري **على** كل طلب.", 'seoTitleAr' => 'الشروط', 'seoTitleEn' => 'Terms'], ['ae', 'sa']);
        $found = app(Policies::class)->find($policy);
        $stores = [Fx::storeId('sa'), Fx::storeId('ae')];
        sort($stores);

        expect($found?->kind)->toBe(PolicyKind::Terms)
            ->and($found?->stores)->toBe($stores)
            ->and($found?->text('en')?->slug)->toBe('terms-and-conditions')
            ->and($found?->text('ar')?->slug)->toBe('الشروط-والأحكام')
            ->and($found?->text('en')?->seoTitle)->toBe('Terms')
            ->and($found?->textVersion)->toBe(0)
            ->and(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('DRAFT')
            ->and(DB::table('content.policy_stores')->where('policy_id', $policy)->count())->toBe(2)
            ->and(Fx::audits('content.policy.created', $policy))->toBe(1);
    });

    it('needs the Arabic title, an English side only with its title, a kind of the list and at least one store', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);

        expect(policiesRefusal(fn () => policiesSave(['titleAr' => null])))->toBe('title_ar:required')
            ->and(policiesRefusal(fn () => policiesSave(['titleEn' => null, 'bodyEn' => 'Text'])))->toBe('title_en:required')
            ->and(policiesRefusal(fn () => policiesSave(['kind' => 'COOKIES'])))->toBe('kind:choice')
            ->and(policiesRefusal(fn () => policiesSave(['seoTitleAr' => str_repeat('س', 71)])))->toBe('seo_title_ar:too_long')
            ->and(policiesRefusal(fn () => policiesSave([], [])))->toBe('stores:too_few')
            ->and(DB::table('content.policies')->count())->toBe(0);
    });

    it('keeps its kind fixed once made, and refuses a save over a newer version', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $policy = policiesSave([]);
        policiesSave(['id' => $policy, 'version' => 1, 'bodyAr' => 'نص آخر']);

        expect(policiesRefusal(fn () => policiesSave(['id' => $policy, 'version' => 2, 'kind' => 'PRIVACY'])))->toBe('kind:kind_fixed')
            ->and(fn () => policiesSave(['id' => $policy, 'version' => 1]))->toThrow(StaleEdit::class)
            ->and(Fx::audits('content.policy.changed', $policy))->toBe(1);
    });

    it('gives every image a body names its file, and publishes only when they are ready', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $pending = Cx::image('PENDING');
        $body = "نص\n\n![الختم](image:1)";

        expect(policiesRefusal(fn () => policiesSave(['bodyAr' => $body])))->toBe('body_ar:image_ref');

        $policy = policiesSave(['bodyAr' => $body, 'imagesAr' => [1 => $pending, 2 => Cx::image()]]);

        expect(DB::table('content.policy_images')->where('policy_id', $policy)->get(['ref', 'media_id', 'alt'])->map(fn ($row) => (array) $row)->all())->toBe([['ref' => 1, 'media_id' => $pending, 'alt' => 'الختم']])
            ->and(fn () => policiesPublish($policy))->toThrow(ImageNotReady::class);
    });
});

describe('the job in every store the policy serves (F20)', function () {
    it('refuses a store picked where the person does not hold the job', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);

        expect(fn () => policiesSave([], ['sa', 'ae']))->toThrow(Unauthorized::class)
            ->and(DB::table('content.policies')->count())->toBe(0);
    });

    it('refuses a person holding the job in one of its two stores a tick in the other, and Publish, Unpublish and Delete; one holding it in neither the policy itself', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'ae']);
        $policy = policiesSave([], ['sa', 'ae']);
        $other = policiesSave(['kind' => 'PRIVACY', 'titleAr' => 'الخصوصية', 'titleEn' => 'Privacy'], ['sa', 'ae']);
        policiesPublish($other);

        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);

        expect(fn () => policiesSave(['id' => $policy, 'version' => 1], ['sa', 'ae']))->toThrow(Unauthorized::class)
            ->and(fn () => app(PublishPolicyHandler::class)->handle(new PublishPolicy($policy, 1)))->toThrow(Unauthorized::class)
            ->and(fn () => app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($other, 2)))->toThrow(Unauthorized::class)
            ->and(fn () => app(DeletePolicyHandler::class)->handle(new DeletePolicy($policy, 1)))->toThrow(Unauthorized::class)
            ->and(policiesVersion($policy))->toBe(1);

        Cx::editor([ContentPermissions::POLICY_MANAGE], ['eg']);

        expect(fn () => app(PublishPolicyHandler::class)->handle(new PublishPolicy($policy, 1)))->toThrow(ContentNotFound::class)
            ->and(fn () => policiesSave(['id' => $policy, 'version' => 1, 'titleEn' => 'Changed'], ['eg']))->toThrow(ContentNotFound::class)
            ->and(fn () => app(PublishPolicyHandler::class)->handle(new PublishPolicy(strtolower((string) Str::ulid()), 1)))->toThrow(ContentNotFound::class)
            ->and(policiesVersion($policy))->toBe(1)
            ->and(DB::table('content.policies')->count())->toBe(2)
            ->and(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('DRAFT');
    });

    it('checks again after the lock a store added to the policy since it was found, for Publish (Unpublish and Delete touch only the stores picked)', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $policy = policiesSave([], ['sa', 'eg']);
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);
        $writer = app(PolicyWriter::class);

        // As if the handler found the policy serving KSA alone, and Egypt was added before the row was locked.
        expect(fn () => $writer->publish($policy, 1, [Fx::storeId('sa')], null))->toThrow(Unauthorized::class)
            ->and(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('DRAFT');

        // Every store it serves checked, the same call goes through.
        $writer->delete($policy, 1, [Fx::storeId('eg'), Fx::storeId('sa')]);

        expect(DB::table('content.policies')->where('id', $policy)->exists())->toBeFalse();
    });

    it('lets a Super Admin change a policy of any stores', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $policy = policiesSave([], ['sa', 'ae', 'eg']);
        policiesPublish($policy);

        expect(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('PUBLISHED');
    });
});

describe('one published policy of a kind per store (S3.1)', function () {
    it('refuses a second published Terms in a store, naming the store and the other\'s title; another store takes its own', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'ae']);
        $ksa = policiesSave(['titleEn' => 'KSA terms', 'titleAr' => 'شروط السعودية']);
        policiesPublish($ksa);
        $both = policiesSave(['titleEn' => 'Gulf terms', 'titleAr' => 'شروط الخليج'], ['sa', 'ae']);
        $refused = null;

        // Named in the language of the person's request (the Arabic panel's by default).
        try {
            policiesPublish($both);
        } catch (PolicyKindTaken $taken) {
            $refused = $taken;
        }

        expect($refused?->store)->toBe(Cx::store('sa')->name->in('ar'))
            ->and($refused?->kind)->toBe('الشروط والأحكام')
            ->and($refused?->title)->toBe('شروط السعودية')
            ->and(DB::table('content.policies')->where('id', $both)->value('status'))->toBe('DRAFT');

        app()->setLocale('en');

        try {
            policiesPublish($both);
        } catch (PolicyKindTaken $taken) {
            $refused = $taken;
        }

        // The spec's sentence exactly, with no "Couldn't publish:" before it: a save adding a store raises it too.
        expect($refused)->toBeInstanceOf(PolicyKindTaken::class)
            ->and(trans('content::errors.policy_kind_taken.detail', $refused?->context() ?? [], 'en'))
            ->toBe(Cx::store('sa')->name->in('en').' already has a published Terms and Conditions: KSA terms.')
            ->and(trans('content::errors.policy_kind_taken.detail', ['store' => 'السعودية', 'kind' => 'الشروط والأحكام', 'title' => 'شروط السعودية'], 'ar'))
            ->toBe('في السعودية سياسة منشورة من نوع الشروط والأحكام: شروط السعودية.');

        $uae = policiesSave(['titleEn' => 'UAE terms', 'titleAr' => 'شروط الإمارات'], ['ae']);
        policiesPublish($uae);

        expect(DB::table('content.policies')->where('id', $uae)->value('status'))->toBe('PUBLISHED')
            // A store added to a published policy is held to the rule too.
            ->and(fn () => policiesSave(['id' => $uae, 'version' => policiesVersion($uae), 'titleEn' => 'UAE terms', 'titleAr' => 'شروط الإمارات'], ['ae', 'sa']))->toThrow(PolicyKindTaken::class)
            ->and(DB::table('content.policy_stores')->where('policy_id', $uae)->pluck('store_id')->all())->toBe([Fx::storeId('ae')]);
    });

    it('lets a store publish two policies of the kind Other, and one of each other kind', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $first = policiesSave(['kind' => 'OTHER', 'titleEn' => 'Fitting', 'titleAr' => 'التركيب']);
        $second = policiesSave(['kind' => 'OTHER', 'titleEn' => 'Care', 'titleAr' => 'العناية']);
        $privacy = policiesSave(['kind' => 'PRIVACY', 'titleEn' => 'Privacy', 'titleAr' => 'الخصوصية']);
        policiesPublish($first);
        policiesPublish($second);
        policiesPublish($privacy);

        expect(DB::table('content.policies')->where('status', 'PUBLISHED')->count())->toBe(3);
    });
});

describe('versions (S3.2, F14)', function () {
    it('keeps each published text as a version: 1 on the first publish, the same when republished unchanged, 2 when a published text changes', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $first = Cx::image();
        $policy = policiesSave(['bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => $first]]);
        policiesPublish($policy);

        expect(policiesTextVersion($policy))->toBe(1)
            ->and(DB::table('content.policy_versions')->where('policy_id', $policy)->pluck('number')->all())->toBe([1])
            ->and(DB::table('content.policy_version_images')->where('policy_id', $policy)->where('number', 1)->value('media_id'))->toBe($first)
            ->and(Fx::audits('content.policy.published', $policy))->toBe(1);

        app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($policy, policiesVersion($policy)));
        policiesPublish($policy);

        expect(policiesTextVersion($policy))->toBe(1)
            ->and(DB::table('content.policy_versions')->where('policy_id', $policy)->count())->toBe(1);

        // An SEO box is not the text a customer accepts: saved on the published policy, still version 1.
        policiesSave(['id' => $policy, 'version' => policiesVersion($policy), 'bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => $first], 'seoTitleAr' => 'الشروط', 'seoTitleEn' => 'Terms']);

        expect(policiesTextVersion($policy))->toBe(1);

        // The image taken out of the live text: version 2, and version 1 keeps its image.
        policiesSave(['id' => $policy, 'version' => policiesVersion($policy), 'bodyAr' => 'نص جديد', 'seoTitleAr' => 'الشروط', 'seoTitleEn' => 'Terms']);
        $two = DB::table('content.policy_versions')->where('policy_id', $policy)->where('number', 2)->first();

        expect(policiesTextVersion($policy))->toBe(2)
            ->and(RichText::fromJson('body_ar', (string) $two?->body_ar)->equals(MarkText::blocks('body_ar', 'نص جديد')))->toBeTrue()
            ->and($two?->title_en)->toBe('Terms and Conditions')
            ->and(DB::table('content.policy_images')->where('policy_id', $policy)->count())->toBe(0)
            ->and(DB::table('content.policy_version_images')->where('policy_id', $policy)->pluck('number')->all())->toBe([1]);
    });

    it('keeps a text changed while a draft as the next version at its next publish', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $policy = policiesSave([]);
        policiesPublish($policy);
        app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($policy, policiesVersion($policy)));
        policiesSave(['id' => $policy, 'version' => policiesVersion($policy), 'titleEn' => 'Terms of sale']);

        expect(policiesTextVersion($policy))->toBe(1);

        policiesPublish($policy);

        expect(policiesTextVersion($policy))->toBe(2)
            ->and(DB::table('content.policy_versions')->where('policy_id', $policy)->orderBy('number')->pluck('title_en')->all())->toBe(['Terms and Conditions', 'Terms of sale']);
    });

    it('blocks deleting an image a version keeps, even once the live text dropped it, and detaches it from a draft only when nothing blocks', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $image = Cx::image();
        $policy = policiesSave(['bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => $image]]);
        $draft = policiesSave(['kind' => 'OTHER', 'titleEn' => 'Care', 'titleAr' => 'العناية', 'bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => $image]]);
        $usage = app(ContentMediaUsage::class);

        expect(array_map(static fn ($use): array => [$use->subjectId, $use->blocksDelete], $usage->usesOf($image)))->toEqualCanonicalizing([[$policy, false], [$draft, false]]);

        policiesPublish($policy);
        policiesSave(['id' => $policy, 'version' => policiesVersion($policy), 'bodyAr' => 'نص جديد']);
        app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($policy, policiesVersion($policy)));

        // Unpublished, the policy shows the image nowhere, yet its version 1 keeps it: the delete is refused, and nothing
        // is detached, the draft's use included.
        expect(DB::table('content.policy_images')->where('policy_id', $policy)->exists())->toBeFalse()
            ->and(array_map(static fn ($use): array => [$use->subjectId, $use->blocksDelete], $usage->usesOf($image)))->toEqualCanonicalizing([[$policy, true], [$draft, false]])
            ->and(fn () => policiesMediaDelete($image))->toThrow(MediaInUse::class)
            ->and(DB::table('content.policy_version_images')->where('media_id', $image)->count())->toBe(1)
            ->and(DB::table('content.policy_images')->where('policy_id', $draft)->where('media_id', $image)->exists())->toBeTrue()
            ->and(DB::table('platform.media')->where('id', $image)->exists())->toBeTrue();

        // A file only the draft uses is detached, its block with it.
        $other = Cx::image();
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        policiesSave(['id' => $draft, 'version' => policiesVersion($draft), 'kind' => 'OTHER', 'titleEn' => 'Care', 'titleAr' => 'العناية', 'bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => $other]]);
        policiesMediaDelete($other);

        expect(DB::table('content.policy_images')->where('policy_id', $draft)->exists())->toBeFalse()
            ->and(app(Policies::class)->find($draft)?->text('ar')?->body->imageRefs())->toBe([])
            ->and(Fx::audits('content.policy.image_detached', $draft))->toBe(1);
    });

    it('reads each kept version by its number', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $policy = policiesSave([]);
        policiesPublish($policy);
        policiesSave(['id' => $policy, 'version' => policiesVersion($policy), 'titleEn' => 'Terms of sale']);
        $versions = app(Policies::class);

        expect($versions->version($policy, 1)?->texts['en']['title'])->toBe('Terms and Conditions')
            ->and($versions->version($policy, 2)?->texts['en']['title'])->toBe('Terms of sale')
            ->and($versions->version($policy, 3))->toBeNull()
            ->and($versions->latestVersion($policy)?->number)->toBe(2);
    });
});

describe('addresses (S3.1; owner, 2026-11-20: per store)', function () {
    it('keeps an address unique among policies per store and language, now or before; another store may use it', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'ae']);
        $ksa = policiesSave([]);
        // The same English address in the UAE: a store of its own.
        $uae = policiesSave(['titleAr' => 'شروط الإمارات'], ['ae']);

        expect(app(Policies::class)->find($uae)?->text('en')?->slug)->toBe('terms-and-conditions')
            ->and(fn () => policiesSave(['kind' => 'OTHER', 'titleAr' => 'شروط أخرى'], ['sa']))->toThrow(SlugTaken::class)
            // A store added to a policy brings its addresses: the UAE's is taken there.
            ->and(fn () => policiesSave(['id' => $ksa, 'version' => 1], ['sa', 'ae']))->toThrow(SlugTaken::class)
            ->and(DB::table('content.policy_stores')->where('policy_id', $ksa)->count())->toBe(1);

        policiesSave(['id' => $ksa, 'version' => 1, 'slugEn' => 'terms-ksa']);

        // The old address forwards to the KSA policy, so it is still held in KSA.
        expect(fn () => policiesSave(['kind' => 'OTHER', 'titleAr' => 'شروط أخرى'], ['sa']))->toThrow(SlugTaken::class)
            ->and(DB::table('content.slug_history')->where('kind', 'POLICY')->where('item_id', $ksa)->where('locale', 'en')->orderBy('slug')->pluck('slug')->all())->toBe(['terms-and-conditions', 'terms-ksa']);

        // Deleted, a draft frees its addresses.
        app(DeletePolicyHandler::class)->handle(new DeletePolicy($ksa, 2));
        $again = policiesSave(['kind' => 'OTHER', 'titleAr' => 'شروط أخرى'], ['sa']);

        expect(app(Policies::class)->find($again)?->text('en')?->slug)->toBe('terms-and-conditions');
    });
});

describe('deleting (owner, 2026-11-20: never once published)', function () {
    it('deletes a policy never published, with its rows and addresses', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $policy = policiesSave(['bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => Cx::image()]]);
        app(DeletePolicyHandler::class)->handle(new DeletePolicy($policy, policiesVersion($policy)));

        foreach (['policy_translations', 'policy_stores', 'policy_images'] as $table) {
            expect(DB::table("content.{$table}")->where('policy_id', $policy)->exists())->toBeFalse($table);
        }

        expect(DB::table('content.policies')->where('id', $policy)->exists())->toBeFalse()
            ->and(DB::table('content.slug_history')->where('item_id', $policy)->exists())->toBeFalse()
            ->and(Fx::audits('content.policy.deleted', $policy))->toBe(1);
    });

    it('refuses to delete a policy once published, published or unpublished since, keeping every version', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $policy = policiesSave(['bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => Cx::image()]]);
        policiesPublish($policy);

        expect(fn () => app(DeletePolicyHandler::class)->handle(new DeletePolicy($policy, policiesVersion($policy))))->toThrow(PolicyNotDeletable::class);

        app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($policy, policiesVersion($policy)));

        expect(fn () => app(DeletePolicyHandler::class)->handle(new DeletePolicy($policy, policiesVersion($policy))))->toThrow(PolicyNotDeletable::class)
            ->and(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('DRAFT')
            ->and(DB::table('content.policy_versions')->where('policy_id', $policy)->count())->toBe(1)
            ->and(DB::table('content.policy_version_images')->where('policy_id', $policy)->count())->toBe(1)
            ->and(Fx::audits('content.policy.deleted', $policy))->toBe(0)
            ->and(trans('content::errors.policy_not_deletable.detail', [], 'en'))->toStartWith('Couldn\'t delete:')
            ->and(trans('content::errors.policy_not_deletable.detail', [], 'ar'))->toStartWith('تعذّر الحذف:');
    });
});

/** @return list<string> the stores' ids, in id order */
function policiesStores(string ...$codes): array
{
    $ids = array_map(static fn (string $code): string => Fx::storeId($code), $codes);
    sort($ids);

    return $ids;
}

/** @return array<string, string> each address of the policy's in a store, `locale:slug` => the policy holding it there */
function policiesAddresses(string $code): array
{
    $held = [];

    foreach (DB::table('content.slug_history')->where('kind', 'POLICY')->where('store_id', Fx::storeId($code))->orderBy('locale')->orderBy('slug')->get() as $row) {
        $held["{$row->locale}:{$row->slug}"] = (string) $row->item_id;
    }

    return $held;
}

describe('a policy shared by several stores, changed for some (owner, 2026-11-20)', function () {
    it('leaves a store the person does not hold on the policy when they save it unchanged, and splits it when they change its words', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policiesSave([], ['sa', 'eg']);
        // Ahmed holds KSA alone: Egypt is not on his form.
        $ahmed = Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);

        expect(policiesSave(['id' => $shared, 'version' => 1], ['sa']))->toBe($shared)
            ->and(app(Policies::class)->find($shared)?->stores)->toBe(policiesStores('sa', 'eg'));

        $ksa = policiesSave(['id' => $shared, 'version' => 2, 'titleEn' => 'KSA terms', 'bodyEn' => 'The KSA terms'], ['sa']);
        $copy = app(Policies::class)->find($ksa);
        $original = app(Policies::class)->find($shared);
        $created = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.policy.created')->where('subject_id', $ksa)->value('changes'), true);
        $split = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.policy.split')->where('subject_id', $shared)->value('changes'), true);

        expect($ksa)->not->toBe($shared)
            ->and($copy?->kind)->toBe(PolicyKind::Terms)
            ->and($copy?->stores)->toBe([Fx::storeId('sa')])
            ->and($copy?->text('en')?->title)->toBe('KSA terms')
            ->and($copy?->status)->toBe(Publication::Draft)
            ->and([$copy?->version, $copy?->textVersion, $copy?->updatedBy])->toBe([1, 0, $ahmed])
            // Egypt's is the original, its words as they were.
            ->and($original?->stores)->toBe([Fx::storeId('eg')])
            ->and($original?->text('en')?->title)->toBe('Terms and Conditions')
            ->and($original?->version)->toBe(3)
            ->and($created['split_from'] ?? null)->toBe([null, $shared])
            ->and($split['split_to'] ?? null)->toBe([null, $ksa])
            ->and($split['stores'] ?? null)->toBe([implode(',', policiesStores('sa', 'eg')), Fx::storeId('eg')]);
    });

    it('takes a share image changed as changed words, and leaves alone a store that is off to the person', function () {
        // Sara holds both, but may not switch stores: Egypt, switched off, is not on her form, and does not block her.
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policiesSave([], ['sa', 'eg']);
        $both = policiesStores('sa', 'eg');
        $egypt = Cx::switchOff('eg');

        expect(fn () => app(SavePolicyHandler::class)->handle(new SavePolicy(policiesInput(['id' => $shared, 'version' => 1, 'storeIds' => $both]))))->toThrow(StoreOff::class)
            ->and(policiesSave(['id' => $shared, 'version' => 1], ['sa']))->toBe($shared)
            ->and(app(Policies::class)->find($shared)?->stores)->toBe($both);

        $image = Cx::image();
        $ksa = policiesSave(['id' => $shared, 'version' => 2, 'shareEn' => $image], ['sa']);

        expect($ksa)->not->toBe($shared)
            ->and(app(Policies::class)->find($ksa)?->shareEn)->toBe($image)
            ->and(app(Policies::class)->find($shared)?->shareEn)->toBeNull()
            ->and(app(Policies::class)->find($shared)?->stores)->toBe([$egypt]);
    });

    it('splits a published policy for the stores ticked: the copy published at its own version 1, the original\'s versions untouched, each store\'s addresses with its own policy', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policiesSave([], ['sa', 'eg']);
        policiesPublish($shared);
        // Every store ticked, a changed address is the one policy's change: no split, the text's version unchanged.
        policiesSave(['id' => $shared, 'version' => policiesVersion($shared), 'slugEn' => 'terms'], ['sa', 'eg']);

        expect(DB::table('content.policies')->count())->toBe(1)
            ->and(policiesTextVersion($shared))->toBe(1);

        // Sara unticks Egypt and changes the words: KSA's own policy, published at once.
        $ksa = policiesSave(['id' => $shared, 'version' => policiesVersion($shared), 'slugEn' => 'terms', 'bodyEn' => 'The new KSA terms'], ['sa']);

        expect(DB::table('content.policies')->where('id', $ksa)->value('status'))->toBe('PUBLISHED')
            ->and(DB::table('content.policies')->where('id', $ksa)->value('published_at'))->not->toBeNull()
            ->and(policiesTextVersion($ksa))->toBe(1)
            ->and(DB::table('content.policy_versions')->where('policy_id', $ksa)->pluck('title_en')->all())->toBe(['Terms and Conditions'])
            ->and(app(Policies::class)->version($ksa, 1)?->texts['en']['body']->equals(MarkText::blocks('body_en', 'The new KSA terms')))->toBeTrue()
            // The original: published, its text and its one version as they were, Egypt alone.
            ->and(DB::table('content.policies')->where('id', $shared)->value('status'))->toBe('PUBLISHED')
            ->and(policiesTextVersion($shared))->toBe(1)
            ->and(DB::table('content.policy_versions')->where('policy_id', $shared)->pluck('number')->all())->toBe([1])
            ->and(app(Policies::class)->find($shared)?->text('en')?->body->equals(MarkText::blocks('body_en', 'The terms')))->toBeTrue()
            // Both keep `terms`, each in its own store; KSA's old address forwards to KSA's policy.
            ->and(policiesAddresses('sa'))->toBe(['ar:الشروط-والأحكام' => $ksa, 'en:terms' => $ksa, 'en:terms-and-conditions' => $ksa])
            ->and(policiesAddresses('eg'))->toBe(['ar:الشروط-والأحكام' => $shared, 'en:terms' => $shared, 'en:terms-and-conditions' => $shared])
            ->and(Fx::audits('content.policy.split', $shared))->toBe(1);
    });

    it('holds the copy of a published policy to Publish\'s rules, writing nothing when refused', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg', 'ae']);
        $shared = policiesSave([], ['sa', 'eg']);
        policiesPublish($shared);
        $uae = policiesSave(['titleEn' => 'UAE terms', 'titleAr' => 'شروط الإمارات'], ['ae']);
        policiesPublish($uae);

        expect(fn () => policiesSave(['id' => $shared, 'version' => policiesVersion($shared), 'bodyEn' => null], ['sa']))->toThrow(MissingEnglish::class)
            // KSA's copy taking the UAE too, where Terms are published already.
            ->and(fn () => policiesSave(['id' => $shared, 'version' => policiesVersion($shared), 'bodyEn' => 'The new terms'], ['sa', 'ae']))->toThrow(PolicyKindTaken::class)
            ->and(DB::table('content.policies')->count())->toBe(2)
            ->and(app(Policies::class)->find($shared)?->stores)->toBe(policiesStores('sa', 'eg'))
            ->and(policiesAddresses('sa'))->toBe(['ar:الشروط-والأحكام' => $shared, 'en:terms-and-conditions' => $shared])
            ->and(Fx::audits('content.policy.split'))->toBe(0);
    });

    it('changes the one shared policy when every store it serves is ticked', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policiesSave([], ['sa', 'eg']);
        policiesPublish($shared);

        expect(policiesSave(['id' => $shared, 'version' => policiesVersion($shared), 'bodyEn' => 'The new terms'], ['sa', 'eg']))->toBe($shared)
            ->and(DB::table('content.policies')->count())->toBe(1)
            ->and(app(Policies::class)->find($shared)?->stores)->toBe(policiesStores('sa', 'eg'))
            ->and(policiesTextVersion($shared))->toBe(2)
            ->and(Fx::audits('content.policy.split'))->toBe(0);
    });

    it('takes a store off a draft when it is unticked with the words unchanged, freeing its addresses there (a published one\'s is turned off: PolicyTurnOffTest)', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
        $shared = policiesSave([], ['sa', 'eg']);

        expect(policiesSave(['id' => $shared, 'version' => policiesVersion($shared)], ['sa']))->toBe($shared)
            ->and(app(Policies::class)->find($shared)?->stores)->toBe([Fx::storeId('sa')])
            ->and(DB::table('content.policies')->where('id', $shared)->value('status'))->toBe('DRAFT')
            ->and(DB::table('content.policies')->count())->toBe(1)
            ->and(policiesAddresses('eg'))->toBe([]);

        // Egypt takes a policy of its own at the same address.
        $egypt = policiesSave(['titleAr' => 'الشروط والأحكام'], ['eg']);
        policiesPublish($egypt);

        expect(app(Policies::class)->find($egypt)?->text('en')?->slug)->toBe('terms-and-conditions')
            ->and(DB::table('content.policies')->where('id', $egypt)->value('status'))->toBe('PUBLISHED');
    });
});

describe('the share image per language (content.md §1.14; owner, 2026-11-20)', function () {
    it('keeps a share image per language, refuses a file that is no public image, and publishes only when it is ready', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $ar = Cx::image();
        $pending = Cx::image('PENDING');

        expect(policiesRefusal(fn () => policiesSave(['shareAr' => Cx::image(visibility: 'PRIVATE')])))->toBe('share_image_ar:image_file')
            ->and(policiesRefusal(fn () => policiesSave(['shareEn' => strtolower((string) Str::ulid())])))->toBe('share_image_en:image_file');

        $policy = policiesSave(['shareAr' => $ar, 'shareEn' => $pending]);

        expect(DB::table('content.policies')->where('id', $policy)->first(['share_media_ar', 'share_media_en']))->toEqual((object) ['share_media_ar' => $ar, 'share_media_en' => $pending])
            ->and(app(Policies::class)->find($policy)?->images())->toMatchArray(['share_image_ar' => $ar, 'share_image_en' => $pending])
            ->and(fn () => policiesPublish($policy))->toThrow(ImageNotReady::class);
    });

    it('blocks deleting a share image while the policy is published, and takes it off a draft', function () {
        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        $image = Cx::image();
        $policy = policiesSave(['shareEn' => $image]);
        policiesPublish($policy);

        expect(fn () => policiesMediaDelete($image))->toThrow(MediaInUse::class);

        Cx::editor([ContentPermissions::POLICY_MANAGE]);
        app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($policy, policiesVersion($policy)));
        $version = policiesVersion($policy);
        policiesMediaDelete($image);

        expect(DB::table('content.policies')->where('id', $policy)->value('share_media_en'))->toBeNull()
            ->and(policiesVersion($policy))->toBe($version + 1)
            ->and(Fx::audits('content.policy.image_detached', $policy))->toBe(1);
    });
});
