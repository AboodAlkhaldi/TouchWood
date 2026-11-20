<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyInput;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\SlugTaken;
use Modules\Content\Domain\Model\Policy;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\Policies;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The last line under policies (flags-2026-11.md S3; content.md §5; CONVENTIONS "Code catches mistakes before the
| database"): each named CHECK, key and unique index refuses the row it is for, and for the rules the use cases hold
| the code refuses first - the constraint is dropped inside the test (its transaction rolls the drop back) and the use
| case still refuses, writing nothing.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @return array<string, mixed> a row the table takes, every column it needs */
function policiesConstraintRow(string $table): array
{
    $now = CarbonImmutable::now();
    $id = strtolower((string) Str::ulid());

    return match ($table) {
        'policies' => ['id' => $id, 'kind' => 'TERMS', 'created_at' => $now, 'updated_at' => $now],
        'policy_translations' => ['policy_id' => policiesConstraintParent(), 'locale' => 'ar', 'title' => 'الشروط', 'slug' => 'الشروط-'.$id, 'body' => '{"blocks": []}'],
        'policy_stores' => ['policy_id' => policiesConstraintParent(), 'store_id' => Fx::storeId('sa')],
        'policy_images' => ['policy_id' => policiesConstraintParent(), 'locale' => 'ar', 'ref' => 1, 'media_id' => Cx::image(), 'alt' => 'صورة'],
        'policy_versions' => ['policy_id' => policiesConstraintParent(), 'number' => 1, 'title_ar' => 'الشروط', 'title_en' => 'Terms', 'body_ar' => '{"blocks": []}', 'body_en' => '{"blocks": []}', 'published_at' => $now],
        'policy_version_images' => [...policiesConstraintVersion(), 'locale' => 'ar', 'ref' => 1, 'media_id' => Cx::image(), 'alt' => 'صورة'],
        default => throw new LogicException("No row for {$table}."),
    };
}

function policiesConstraintParent(): string
{
    $row = policiesConstraintRow('policies');
    DB::table('content.policies')->insert($row);

    return (string) $row['id'];
}

/** @return array{policy_id: string, number: int} */
function policiesConstraintVersion(): array
{
    $row = policiesConstraintRow('policy_versions');
    DB::table('content.policy_versions')->insert($row);

    return ['policy_id' => (string) $row['policy_id'], 'number' => 1];
}

/**
 * The constraint a row breaks, by its name in PostgreSQL's words.
 *
 * @param  array<string, mixed>  $row
 */
function policiesConstraintBroken(string $table, array $row): string
{
    try {
        DB::transaction(fn () => DB::table("content.{$table}")->insert($row));
    } catch (QueryException $refused) {
        preg_match('/(?:constraint|index) "([a-z_]+)"/', $refused->getMessage(), $found);

        return $found[1] ?? $refused->getMessage();
    }

    return 'accepted';
}

it('takes the base rows these cases break, so each break is the only thing wrong', function (string $table) {
    expect(policiesConstraintBroken($table, policiesConstraintRow($table)))->toBe('accepted');
})->with(['policies', 'policy_translations', 'policy_stores', 'policy_images', 'policy_versions', 'policy_version_images']);

it('refuses each row its CHECK or key is for', function (string $table, array $with, string $constraint) {
    expect(policiesConstraintBroken($table, [...policiesConstraintRow($table), ...$with]))->toBe($constraint);
})->with([
    'a policy of no kind' => ['policies', ['kind' => 'COOKIES'], 'policies_kind'],
    'a policy of no state' => ['policies', ['status' => 'LIVE'], 'policies_status'],
    'a policy published with no moment' => ['policies', ['status' => 'PUBLISHED', 'published_at' => null, 'text_version' => 1], 'policies_published'],
    'a draft with a moment of publishing' => ['policies', ['published_at' => '2027-01-01 00:00:00+00'], 'policies_published'],
    'a text version below 0' => ['policies', ['text_version' => -1], 'policies_text_version'],
    'a policy published with no version of its text' => ['policies', ['status' => 'PUBLISHED', 'published_at' => '2027-01-01 00:00:00+00', 'text_version' => 0], 'policies_text_version'],
    'an edit version of 0' => ['policies', ['version' => 0], 'policies_version'],
    'a share image of no file' => ['policies', ['share_media_ar' => strtolower((string) Str::ulid())], 'policies_share_ar'],
    'an English share image of no file' => ['policies', ['share_media_en' => strtolower((string) Str::ulid())], 'policies_share_en'],
    'a text in no language' => ['policy_translations', ['locale' => 'fr'], 'polt_locale'],
    'a blank title' => ['policy_translations', ['title' => ' '], 'polt_title'],
    'an address with a slash' => ['policy_translations', ['slug' => 'a/b'], 'polt_slug'],
    'a text with no blocks' => ['policy_translations', ['body' => '{}'], 'polt_body'],
    'a store that is not there' => ['policy_stores', ['store_id' => strtolower((string) Str::ulid())], 'pols_store'],
    'a body image of ref 0' => ['policy_images', ['ref' => 0], 'poli_ref'],
    'a body image in no language' => ['policy_images', ['locale' => 'fr'], 'poli_locale'],
    'a body image of no file' => ['policy_images', ['media_id' => strtolower((string) Str::ulid())], 'poli_media'],
    'a version numbered 0' => ['policy_versions', ['number' => 0], 'polv_number'],
    'a version with a blank English title' => ['policy_versions', ['title_en' => ' '], 'polv_titles'],
    'a version whose English body has no blocks' => ['policy_versions', ['body_en' => '{}'], 'polv_bodies'],
    'a version image of ref 0' => ['policy_version_images', ['ref' => 0], 'polvi_ref'],
    'a version image in no language' => ['policy_version_images', ['locale' => 'fr'], 'polvi_locale'],
    'a version image of no file' => ['policy_version_images', ['media_id' => strtolower((string) Str::ulid())], 'polvi_media'],
]);

it('lets two policies share an address in a language unless in one store: the history\'s unique index, and a file deleted under a version', function () {
    $taken = policiesConstraintRow('policy_translations');
    DB::table('content.policy_translations')->insert($taken);
    $image = policiesConstraintRow('policy_version_images');
    DB::table('content.policy_version_images')->insert($image);
    $history = fn (string $code): array => ['id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId($code), 'kind' => 'POLICY', 'item_id' => strtolower((string) Str::ulid()), 'locale' => 'en', 'slug' => 'terms', 'created_at' => CarbonImmutable::now()];
    DB::table('content.slug_history')->insert($history('sa'));

    expect(policiesConstraintBroken('policy_translations', [...policiesConstraintRow('policy_translations'), 'slug' => $taken['slug']]))->toBe('accepted')
        ->and(policiesConstraintBroken('slug_history', $history('sa')))->toBe('slug_history_unique')
        ->and(policiesConstraintBroken('slug_history', $history('eg')))->toBe('accepted')
        ->and(fn () => DB::transaction(fn () => DB::table('platform.media')->where('id', $image['media_id'])->delete()))->toThrow(QueryException::class);
});

it('takes POLICY in the address history and Translate\'s marks, and nothing unknown', function () {
    $now = CarbonImmutable::now();
    $id = strtolower((string) Str::ulid());

    expect(policiesConstraintBroken('slug_history', ['id' => $id, 'store_id' => Fx::storeId('sa'), 'kind' => 'POLICY', 'item_id' => $id, 'locale' => 'en', 'slug' => 'terms', 'created_at' => $now]))->toBe('accepted')
        ->and(policiesConstraintBroken('slug_history', ['id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'kind' => 'NOTICE', 'item_id' => $id, 'locale' => 'en', 'slug' => 'notice', 'created_at' => $now]))->toBe('slug_history_kind')
        ->and(policiesConstraintBroken('unread_translations', ['kind' => 'POLICY', 'item_id' => $id, 'store_id' => Fx::storeId('sa'), 'marked_at' => $now]))->toBe('accepted')
        ->and(policiesConstraintBroken('unread_translations', ['kind' => 'NOTICE', 'item_id' => $id, 'store_id' => Fx::storeId('sa'), 'marked_at' => $now]))->toBe('unread_translations_kind');
});

/*
| Each case below drops only the constraint it is for, after making what it needs, and then asks the use case for the
| row that constraint would refuse: the code must refuse it, in its own words, writing nothing. With the code's check
| taken out the row would be written (the constraint gone), so the case fails - none passes on another check.
*/
it('refuses in the code first, with the constraint dropped behind it', function (array $constraints, string $table, Closure $prepare, Closure $work, string $refusal) {
    Cx::editor([ContentPermissions::POLICY_MANAGE]);
    $made = $prepare();

    foreach ($constraints as $owner => $constraint) {
        DB::statement("ALTER TABLE content.{$owner} DROP CONSTRAINT {$constraint}");
    }

    $before = DB::table("content.{$table}")->count();

    expect(fn () => $work($made))->toThrow($refusal)
        ->and(DB::table("content.{$table}")->count())->toBe($before);
})->with([
    'a policy of no kind' => [['policies' => 'policies_kind'], 'policies', fn () => null, function (): void {
        Cx::policy(['kind' => 'COOKIES']);
    }, InvalidContentInput::class],
    'an address with a slash' => [['policy_translations' => 'polt_slug'], 'policy_translations', fn () => null, function (): void {
        Cx::policy(['slugEn' => 'a/b']);
    }, InvalidContentInput::class],
    'a blank Arabic title' => [['policy_translations' => 'polt_title'], 'policy_translations', fn () => null, function (): void {
        Cx::policy(['titleAr' => ' ']);
    }, InvalidContentInput::class],
    'a store that is not there' => [['policy_stores' => 'pols_store'], 'policy_stores', function (): void {
        // A Super Admin holds the job in every store, so only the store's own check is left to refuse it.
        Fx::actAsStaff(Fx::staff(superAdmin: true));
    }, function (): void {
        app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(null, 'TERMS', [strtolower((string) Str::ulid())], 0, 'الشروط', 'Terms')));
    }, InvalidContentInput::class],
    'a body image of no file' => [['policy_images' => 'poli_media'], 'policy_images', fn () => null, function (): void {
        Cx::policy(['bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => strtolower((string) Str::ulid())]]);
    }, InvalidContentInput::class],
    'an Arabic share image of no file' => [['policies' => 'policies_share_ar'], 'policies', fn () => null, function (): void {
        Cx::policy(['shareAr' => strtolower((string) Str::ulid())]);
    }, InvalidContentInput::class],
    'an English share image of no file' => [['policies' => 'policies_share_en'], 'policies', fn () => null, function (): void {
        Cx::policy(['shareEn' => strtolower((string) Str::ulid())]);
    }, InvalidContentInput::class],
    'a version with no English title' => [['policy_versions' => 'polv_titles'], 'policy_versions', fn (): string => Cx::policy(['titleEn' => null, 'bodyEn' => null]), function (string $policy): void {
        app(PublishPolicyHandler::class)->handle(new PublishPolicy($policy, 1));
    }, MissingEnglish::class],
    'a version keeping an image of no file' => [['policy_images' => 'poli_media', 'policy_version_images' => 'polvi_media'], 'policy_version_images', function (): string {
        $image = Cx::image();
        $policy = Cx::policy(['bodyAr' => "نص\n\n![الختم](image:1)", 'imagesAr' => [1 => $image]]);

        return implode('|', [$policy, $image]);
    }, function (string $made): void {
        [$policy, $image] = explode('|', $made);
        // The file gone from under the draft (its key dropped): Publish finds it no image ready to show.
        DB::table('platform.media')->where('id', $image)->delete();
        app(PublishPolicyHandler::class)->handle(new PublishPolicy($policy, 1));
    }, ImageNotReady::class],
    'an address another policy holds in the store' => [['slug_history' => 'slug_history_unique'], 'slug_history', fn (): string => Cx::policy(['titleAr' => 'الشروط', 'titleEn' => 'Terms']), function (): void {
        Cx::policy(['kind' => 'OTHER', 'titleAr' => 'شروط أخرى', 'titleEn' => 'Terms']);
    }, SlugTaken::class],
]);

it('refuses a policy published with no moment or no text version, a draft with a moment, and an edit version of 0, in the domain first, the CHECKs dropped', function () {
    Cx::editor([ContentPermissions::POLICY_MANAGE]);
    $was = app(Policies::class)->find(Cx::policy()) ?? throw new LogicException('Not saved.');
    $now = CarbonImmutable::now();

    foreach (['policies_published', 'policies_text_version', 'policies_version'] as $constraint) {
        DB::statement("ALTER TABLE content.policies DROP CONSTRAINT {$constraint}");
    }

    $write = fn (Publication $status, ?CarbonImmutable $at, int $textVersion, int $version = 2) => app(Policies::class)->updateRow(new Policy($was->id, $was->kind, $was->stores, $status, $at, $was->texts, null, null, $textVersion, $version, null, $now));

    expect(fn () => $write(Publication::Published, null, 1))->toThrow(LogicException::class)
        ->and(fn () => $write(Publication::Draft, $now, 0))->toThrow(LogicException::class)
        ->and(fn () => $write(Publication::Published, $now, 0))->toThrow(LogicException::class)
        ->and(fn () => $write(Publication::Draft, null, -1))->toThrow(LogicException::class)
        ->and(fn () => $write(Publication::Draft, null, 0, 0))->toThrow(LogicException::class)
        ->and((array) DB::table('content.policies')->where('id', $was->id)->first(['status', 'published_at', 'text_version', 'version']))->toBe(['status' => 'DRAFT', 'published_at' => null, 'text_version' => 0, 'version' => 1]);
});

it('names the address refused, so the person knows which', function () {
    Cx::editor([ContentPermissions::POLICY_MANAGE]);
    Cx::policy(['titleAr' => 'الشروط', 'titleEn' => 'Terms']);

    try {
        Cx::policy(['kind' => 'OTHER', 'titleAr' => 'شروط أخرى', 'titleEn' => 'Terms']);
    } catch (SlugTaken $taken) {
        expect($taken->locale)->toBe('en')->and($taken->slug)->toBe('terms');

        return;
    }

    throw new LogicException('Not refused.');
});
