<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaults;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaultsHandler;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentRedirects as Rx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The robots file (content.md §1.14, §9.3 #28, §8 #15): one generated /robots.txt with each on store's lines - its
| addresses allowed, Disallow for /admin, cart, checkout, account, search and the paths typed on its SEO screen, and
| the sitemap index - and anywhere but production, Disallow: / for everything. Asked over HTTP: a static
| public/robots.txt would answer before the route (plan's trap).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    config(['app.url' => 'https://touchwood.test']);
});

function robotsAsProduction(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

it('has no static file in the way', function () {
    expect(file_exists(public_path('robots.txt')))->toBeFalse();
});

it('says Disallow: / anywhere but production', function () {
    get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect((string) get('/robots.txt')->getContent())->toBe("User-agent: *\nDisallow: /\n");
});

it('writes each on store\'s lines in production, and the sitemap index', function () {
    Cx::editor([ContentPermissions::SEO_MANAGE], ['sa']);
    app(SaveSeoDefaultsHandler::class)->handle(new SaveSeoDefaults(Fx::storeId('sa'), 'تاتش وود', 'TouchWood', ':title | :site', ':title | :site', 'وصف', 'A description', null, "/brands/old\n/عروض"));
    Rx::storeOn('eg', false);
    robotsAsProduction();

    $text = (string) get('/robots.txt')->assertOk()->getContent();
    $lines = explode("\n", $text);

    expect($lines[0])->toBe('User-agent: *')
        ->and($lines)->toContain('Disallow: /admin', 'Allow: /sa/', 'Allow: /ae/')
        ->and($lines)->toContain('Disallow: /sa/ar/cart', 'Disallow: /sa/en/checkout', 'Disallow: /ae/ar/account', 'Disallow: /ae/en/search')
        ->and($lines)->toContain('Disallow: /sa/ar/brands/old', 'Disallow: /sa/en/brands/old', 'Disallow: /sa/ar/'.rawurlencode('عروض'))
        // Typed for one store only.
        ->and($lines)->not->toContain('Disallow: /ae/ar/brands/old')
        // An off store is not in it.
        ->and($text)->not->toContain('/eg/')
        ->and($text)->not->toContain('Disallow: /'."\n")
        ->and($text)->toEndWith("\nSitemap: https://touchwood.test/sitemap.xml\n");
});
