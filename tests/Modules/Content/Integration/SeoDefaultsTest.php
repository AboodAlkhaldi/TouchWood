<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Content\Application\Command\RequestSitemapRebuild\RequestSitemapRebuild;
use Modules\Content\Application\Command\RequestSitemapRebuild\RequestSitemapRebuildHandler;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaults;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaultsHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\Seo\SeoHandler;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\StoreOff;
use Modules\Content\Infrastructure\Queue\BuildSitemapsJob;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentRedirects as Rx;

use function Pest\Laravel\seed;

/*
| SEO defaults (content.md §1.14, W10): per store, both languages at once (§1.1 rule 2), a title pattern holding
| :title, a share image ready, robots lines as shop paths; and Rebuild Sitemap, one waiting at a time.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Cx::editor([ContentPermissions::SEO_MANAGE], ['sa']);
});

/**
 * @param  array<string, mixed>  $with
 */
function seoSave(array $with = []): void
{
    app(SaveSeoDefaultsHandler::class)->handle(new SaveSeoDefaults(...[
        'storeId' => Fx::storeId('sa'),
        'siteNameAr' => 'تاتش وود',
        'siteNameEn' => 'TouchWood',
        'titlePatternAr' => ':title | :site',
        'titlePatternEn' => ':title | :site',
        'descriptionAr' => 'أثاث وإكسسوارات',
        'descriptionEn' => 'Furniture fittings',
        'shareMediaId' => null,
        'robotsLines' => null,
        ...$with,
    ]));
}

it('saves a store\'s defaults, audited by value', function () {
    $image = Cx::image();
    seoSave(['shareMediaId' => strtoupper($image), 'robotsLines' => "  /brands/old \n\n/brands/old\n/عروض-١"]);

    $page = app(SeoHandler::class)->page(Fx::storeId('sa'));

    expect($page['defaults'])->toBe([
        'site_name_ar' => 'تاتش وود',
        'site_name_en' => 'TouchWood',
        'title_pattern_ar' => ':title | :site',
        'title_pattern_en' => ':title | :site',
        'description_ar' => 'أثاث وإكسسوارات',
        'description_en' => 'Furniture fittings',
        'share_media_id' => $image,
        'robots_extra' => "/brands/old\n/عروض-١",
    ])
        ->and($page['sitemap'])->toBe(['builtAt' => null, 'urls' => 0, 'parts' => 0, 'rebuildWaits' => false])
        ->and(Fx::audits('content.seo.saved', Fx::storeId('sa')))->toBe(1);

    // Saved again, the same: last save wins (no version).
    seoSave(['siteNameEn' => 'Touch Wood']);
    expect(DB::table('content.seo_defaults')->value('site_name_en'))->toBe('Touch Wood');
});

it('needs both languages of every box', function (string $box, string $error) {
    expect(fn () => seoSave([$box => '']))->toThrow($error);
})->with([
    ['siteNameEn', MissingEnglish::class],
    ['descriptionEn', MissingEnglish::class],
    ['titlePatternEn', MissingEnglish::class],
    ['siteNameAr', InvalidContentInput::class],
    ['descriptionAr', InvalidContentInput::class],
]);

it('refuses a title pattern without :title, a box over its length, a box on two lines', function (array $with, string $attribute, string $rule) {
    try {
        seoSave($with);
    } catch (InvalidContentInput $refused) {
        expect([$refused->attribute, $refused->rule])->toBe([$attribute, $rule]);

        return;
    }

    throw new LogicException('Not refused.');
})->with([
    'no :title' => [['titlePatternEn' => ':site'], 'title_pattern_en', InvalidContentInput::TITLE_PATTERN],
    'a site name past 60' => [['siteNameAr' => str_repeat('ا', 61)], 'site_name_ar', InvalidContentInput::TOO_LONG],
    'a description past 160' => [['descriptionEn' => str_repeat('a', 161)], 'description_en', InvalidContentInput::TOO_LONG],
    'two lines' => [['siteNameEn' => "Touch\nWood"], 'site_name_en', InvalidContentInput::ONE_LINE],
]);

it('refuses a robots line that is no shop path, naming its line, and more than 50', function (string $typed, ?int $line) {
    try {
        seoSave(['robotsLines' => $typed]);
    } catch (InvalidContentInput $refused) {
        expect($refused->attribute)->toBe('robots_extra')->and($refused->typedLine)->toBe($line);

        return;
    }

    throw new LogicException('Not refused.');
})->with([
    'no first slash' => ["/ok\nbrands", 2],
    'another site' => ['//elsewhere.test', 1],
    'a space' => ['/a b', 1],
    'a comment mark' => ['/a#b', 1],
    'too long' => ['/'.str_repeat('a', 200), 1],
    'too many' => [implode("\n", array_map(static fn (int $n): string => "/p{$n}", range(1, 51))), null],
]);

it('takes a share image only once its sizes are ready', function () {
    expect(fn () => seoSave(['shareMediaId' => Cx::image('PENDING')]))->toThrow(ImageNotReady::class)
        ->and(fn () => seoSave(['shareMediaId' => Cx::image(visibility: 'PRIVATE')]))->toThrow(InvalidContentInput::class);
});

it('is the store\'s own job: refused in another store, and in an off one to whoever may not switch stores', function () {
    expect(fn () => seoSave(['storeId' => Fx::storeId('ae')]))->toThrow(Unauthorized::class);

    Cx::editor([ContentPermissions::SEO_MANAGE], ['eg']);
    $eg = Rx::storeOn('eg', false);

    expect(fn () => seoSave(['storeId' => $eg]))->toThrow(StoreOff::class);
});

it('queues one rebuild at a time from the SEO screen', function () {
    Queue::fake();
    $rebuild = static fn (): bool => app(RequestSitemapRebuildHandler::class)->handle(new RequestSitemapRebuild(Fx::storeId('sa')));

    expect($rebuild())->toBeTrue()
        ->and($rebuild())->toBeFalse()
        ->and(app(SeoHandler::class)->page(Fx::storeId('sa'))['sitemap']['rebuildWaits'])->toBeTrue();
    Queue::assertPushed(BuildSitemapsJob::class, 1);
    expect(fn () => app(RequestSitemapRebuildHandler::class)->handle(new RequestSitemapRebuild(Fx::storeId('ae'))))->toThrow(Unauthorized::class);
});
