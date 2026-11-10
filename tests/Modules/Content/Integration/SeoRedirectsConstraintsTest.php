<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\AddRedirect\AddRedirect;
use Modules\Content\Application\Command\AddRedirect\AddRedirectHandler;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaults;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaultsHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentRedirects as Rx;

use function Pest\Laravel\seed;

/*
| The last line under SEO's and the redirects' tables (content.md §5; lessons 15, 35, 36, 162): each named CHECK refuses
| the row it is for - those on nullable columns broken with the column left NULL - and, for the rules the use cases
| hold, the code refuses first: the constraint dropped inside the test (its transaction rolls the drop back), the use
| case still refuses, writing nothing.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @return array<string, mixed> a row the table takes */
function seoConstraintRow(string $table): array
{
    $now = CarbonImmutable::now();

    return match ($table) {
        'seo_defaults' => ['store_id' => Fx::storeId('sa'), 'site_name_ar' => 'اسم', 'site_name_en' => 'Name', 'title_pattern_ar' => ':title', 'title_pattern_en' => ':title', 'description_ar' => 'وصف', 'description_en' => 'Text', 'updated_at' => $now],
        'sitemaps' => ['store_id' => Fx::storeId('sa'), 'part' => 1, 'file_key' => 'content/sitemaps/x.xml', 'urls' => 10, 'built_at' => $now],
        'redirects' => ['old_path' => '/old', 'new_path' => '/sa/ar', 'source' => 'HAND', 'created_at' => $now, 'updated_at' => $now],
        'redirect_uploads' => ['id' => strtolower((string) Str::ulid()), 'lines' => '[]', 'state' => 'CHECKED', 'created_at' => $now],
        default => throw new LogicException("No row for {$table}."),
    };
}

it('takes a good row of each table', function (string $table) {
    DB::table("content.{$table}")->insert(seoConstraintRow($table));

    expect(DB::table("content.{$table}")->count())->toBeGreaterThanOrEqual(1);
})->with(['seo_defaults', 'sitemaps', 'redirects', 'redirect_uploads']);

it('refuses what each CHECK is for', function (string $table, array $change, string $constraint) {
    expect(fn () => DB::transaction(fn () => DB::table("content.{$table}")->insert([...seoConstraintRow($table), ...$change])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'a site name of spaces' => ['seo_defaults', ['site_name_en' => '  '], 'seo_defaults_words'],
    'a description of nothing' => ['seo_defaults', ['description_ar' => ''], 'seo_defaults_words'],
    'a pattern without :title' => ['seo_defaults', ['title_pattern_ar' => ':site'], 'seo_defaults_pattern'],
    'robots lines past 10,000' => ['seo_defaults', ['robots_extra' => str_repeat('a', 10001)], 'seo_defaults_robots'],
    'a part 0' => ['sitemaps', ['part' => 0], 'sitemaps_part'],
    'over 50,000 addresses' => ['sitemaps', ['urls' => 50001], 'sitemaps_urls'],
    'no file' => ['sitemaps', ['file_key' => ''], 'sitemaps_file'],
    'an old address with no slash' => ['redirects', ['old_path' => 'old'], 'redirects_old'],
    'an old address ending in one' => ['redirects', ['old_path' => '/old/'], 'redirects_old'],
    'the front page' => ['redirects', ['old_path' => '/'], 'redirects_old'],
    'a new address of no shop path' => ['redirects', ['new_path' => '/blog'], 'redirects_new'],
    'a new address of no language' => ['redirects', ['new_path' => '/sa/fr/x'], 'redirects_new'],
    'a new address ending in a slash' => ['redirects', ['new_path' => '/sa/ar/'], 'redirects_new'],
    'an old address with a dot-dot word' => ['redirects', ['old_path' => '/a/../b'], 'redirects_words'],
    'an old address with an empty word' => ['redirects', ['old_path' => '/a//b'], 'redirects_words'],
    'a new address with dot-dot words' => ['redirects', ['new_path' => '/sa/ar/../../admin'], 'redirects_words'],
    'a new address with a dot word' => ['redirects', ['new_path' => '/sa/ar/./x'], 'redirects_words'],
    'a final address with dot-dot words' => ['redirects', ['check_result' => 'FORWARDS', 'checked_at' => CarbonImmutable::now(), 'check_final_path' => '/sa/ar/..'], 'redirects_words'],
    'visits below 0' => ['redirects', ['visits' => -1], 'redirects_visits'],
    'a source of none' => ['redirects', ['source' => 'IMPORT'], 'redirects_source'],
    'a mark of none' => ['redirects', ['check_result' => 'MAYBE', 'checked_at' => CarbonImmutable::now()], 'redirects_check'],
    'a mark with no moment' => ['redirects', ['check_result' => 'FINE'], 'redirects_checked'],
    'a moment with no mark, the mark NULL' => ['redirects', ['checked_at' => CarbonImmutable::now()], 'redirects_checked'],
    'forwarding with no final address' => ['redirects', ['check_result' => 'FORWARDS', 'checked_at' => CarbonImmutable::now()], 'redirects_final'],
    'a final address with the mark NULL' => ['redirects', ['check_final_path' => '/sa/ar/x'], 'redirects_final'],
    'a final address on a mark that is not forwarding' => ['redirects', ['check_result' => 'FINE', 'checked_at' => CarbonImmutable::now(), 'check_final_path' => '/sa/ar/x'], 'redirects_final'],
    'lines that are not a list' => ['redirect_uploads', ['lines' => '{}'], 'redirect_uploads_lines'],
    'a state of none' => ['redirect_uploads', ['state' => 'WAITING'], 'redirect_uploads_state'],
    'applied with no moment' => ['redirect_uploads', ['state' => 'APPLIED'], 'redirect_uploads_applied'],
    'a moment while checked' => ['redirect_uploads', ['applied_at' => CarbonImmutable::now()], 'redirect_uploads_applied'],
]);

it('refuses a waiting job of no kind, and wanted now with nothing wanted', function () {
    expect(fn () => DB::transaction(fn () => DB::table('content.waiting_jobs')->insert(['job' => 'OTHER'])))->toThrow(QueryException::class, 'waiting_jobs_job')
        ->and(fn () => DB::transaction(fn () => DB::table('content.waiting_jobs')->where('job', 'BUILD_SITEMAPS')->update(['wanted_now' => true, 'wanted_at' => null])))->toThrow(QueryException::class, 'waiting_jobs_wanted');
});

describe('the code refuses first', function () {
    it('refuses a redirect from a store\'s code with redirects_old dropped', function () {
        Rx::superAdmin();
        DB::statement('ALTER TABLE content.redirects DROP CONSTRAINT redirects_old');
        DB::statement('ALTER TABLE content.redirects DROP CONSTRAINT redirects_new');
        DB::statement('ALTER TABLE content.redirects DROP CONSTRAINT redirects_words');

        expect(fn () => app(AddRedirectHandler::class)->handle(new AddRedirect('/', '/sa/ar')))->toThrow(ContentError::class)
            ->and(fn () => app(AddRedirectHandler::class)->handle(new AddRedirect('/old', '/blog')))->toThrow(ContentError::class)
            ->and(fn () => app(AddRedirectHandler::class)->handle(new AddRedirect('/old', '/sa/ar/../../admin')))->toThrow(ContentError::class)
            ->and(fn () => app(AddRedirectHandler::class)->handle(new AddRedirect('/a/../b', '/sa/ar')))->toThrow(ContentError::class)
            ->and(DB::table('content.redirects')->count())->toBe(0);
    });

    it('refuses SEO defaults with one language or no :title with their CHECKs dropped', function () {
        Cx::editor([ContentPermissions::SEO_MANAGE], ['sa']);
        DB::statement('ALTER TABLE content.seo_defaults DROP CONSTRAINT seo_defaults_words');
        DB::statement('ALTER TABLE content.seo_defaults DROP CONSTRAINT seo_defaults_pattern');
        $save = static fn (string $nameEn, string $pattern) => app(SaveSeoDefaultsHandler::class)->handle(new SaveSeoDefaults(Fx::storeId('sa'), 'اسم', $nameEn, $pattern, $pattern, 'وصف', 'Text', null, null));

        expect(fn () => $save('', ':title'))->toThrow(ContentError::class)
            ->and(fn () => $save('Name', ':site'))->toThrow(ContentError::class)
            ->and(DB::table('content.seo_defaults')->count())->toBe(0);
    });
});
