<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\AddRedirect\AddRedirect;
use Modules\Content\Application\Command\AddRedirect\AddRedirectHandler;
use Modules\Content\Application\Command\ChangeRedirect\ChangeRedirect;
use Modules\Content\Application\Command\ChangeRedirect\ChangeRedirectHandler;
use Modules\Content\Application\Command\DeleteRedirect\DeleteRedirect;
use Modules\Content\Application\Command\DeleteRedirect\DeleteRedirectHandler;
use Modules\Content\Application\Command\ResetRedirectCount\ResetRedirectCount;
use Modules\Content\Application\Command\ResetRedirectCount\ResetRedirectCountHandler;
use Modules\Content\Application\Command\UseFinalAddress\UseFinalAddress;
use Modules\Content\Application\Command\UseFinalAddress\UseFinalAddressHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\Redirects\RedirectsHandler;
use Modules\Content\Application\Redirects\RedirectAudit;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\RedirectInvalid;
use Modules\Content\Domain\Model\Redirect;
use Modules\Platform\Application\Query\StoreDirectory;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentRedirects as Rx;

use function Pest\Laravel\seed;

/*
| The redirects' lines (content.md §1.15, §7 RedirectInvalid, W11): Super Admins only, store-free. Both addresses
| checked before anything is written - an old address beginning with any store's code, an off store's included, or a
| reserved path, refused; a new one only a full shop path on a store that exists - and every change audited.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    config(['app.url' => 'https://touchwood.test']);
    Rx::superAdmin();
});

function redirectAdd(?string $old, ?string $new): string
{
    return app(AddRedirectHandler::class)->handle(new AddRedirect($old, $new));
}

/** The refusal's rule, or 'none'. */
function redirectRefusal(callable $work): string
{
    try {
        $work();
    } catch (RedirectInvalid $refused) {
        return $refused->rule;
    }

    return 'none';
}

describe('adding a line', function () {
    it('keeps the old address decoded, without its trailing slash, query string or host, and audits it', function () {
        $kept = redirectAdd(' https://www.touchwood.test/%D9%85%D9%86%D8%AA%D8%AC/?page=2#top ', '/sa/ar/products/x/');

        expect($kept)->toBe('/منتج')
            ->and(DB::table('content.redirects')->where('old_path', '/منتج')->first(['new_path', 'source', 'visits']))->toEqual((object) ['new_path' => '/sa/ar/products/x', 'source' => 'HAND', 'visits' => 0])
            ->and(Fx::audits('content.redirect.added', RedirectAudit::subject('/منتج')))->toBe(1);
    });

    it('allows a single word', function () {
        expect(redirectAdd('/blog', '/sa/ar'))->toBe('/blog')->and(redirectAdd('contact', '/sa/en'))->toBe('/contact');
    });

    it('refuses an old address that is no path of this site', function (string $old) {
        expect(redirectRefusal(fn () => redirectAdd($old, '/sa/ar')))->toBe(RedirectInvalid::OLD_PATH);
    })->with([
        'empty' => '',
        'the front page' => '/',
        'only slashes' => '///',
        'another site' => 'https://other.test/x',
        'another site, with //' => '//other.test/x',
        'a backslash' => '/a%5Cb',
        'a control character' => '/a%00b',
        'not UTF-8' => '/a%C3%28',
        // PR 4 review: a word a browser would resolve, or none at all.
        'a dot-dot word' => '/a/../b',
        'a dot word' => '/a/./b',
        'a dot-dot word encoded' => '/%2e%2e/x',
        'a dot-dot word at the end' => '/a/..',
        'an empty word' => '/a//b',
        'a word of a dot alone' => '/.',
    ]);

    it('refuses an old address beginning with any store\'s code, an off store\'s included', function () {
        Rx::storeOn('eg', false);

        expect(redirectRefusal(fn () => redirectAdd('/sa', '/sa/ar')))->toBe(RedirectInvalid::OLD_STORE)
            ->and(redirectRefusal(fn () => redirectAdd('/ae/old', '/sa/ar')))->toBe(RedirectInvalid::OLD_STORE)
            ->and(redirectRefusal(fn () => redirectAdd('/eg', '/sa/ar')))->toBe(RedirectInvalid::OLD_STORE)
            ->and(redirectRefusal(fn () => redirectAdd('/eg/x/y', '/sa/ar')))->toBe(RedirectInvalid::OLD_STORE);
    });

    it('refuses an old address beginning with a reserved path or one of the site\'s own files', function (string $old) {
        expect(redirectRefusal(fn () => redirectAdd($old, '/sa/ar')))->toBe(RedirectInvalid::OLD_RESERVED);
    })->with(['/admin', '/admin/products', '/Admin/x', '/build/app.js', '/api/x', '/robots.txt', '/sitemap.xml', '/sitemap-2.xml']);

    it('refuses a new address that is not a full shop path, or on no store', function (string $new, string $rule) {
        expect(redirectRefusal(fn () => redirectAdd('/old', $new)))->toBe($rule);
    })->with([
        'no language' => ['/sa', RedirectInvalid::NEW_PATH],
        'a language of no shop' => ['/sa/fr/x', RedirectInvalid::NEW_PATH],
        'a query string' => ['/sa/ar/search?q=chairs', RedirectInvalid::NEW_PATH],
        'no path' => ['', RedirectInvalid::NEW_PATH],
        'another site' => ['https://other.test/sa/ar', RedirectInvalid::NEW_PATH],
        'a first word of no store' => ['/zz/ar/x', RedirectInvalid::NEW_STORE],
        'a chain: the new address an old one' => ['/blog/ar', RedirectInvalid::NEW_STORE],
        // PR 4 review: the Location would resolve to /admin.
        'dot-dot words out of the shop' => ['/sa/ar/../../admin', RedirectInvalid::NEW_PATH],
        'dot-dot words encoded' => ['/sa/ar/%2e%2e/%2E%2E/admin', RedirectInvalid::NEW_PATH],
        'a dot word' => ['/sa/ar/./x', RedirectInvalid::NEW_PATH],
        'an empty word' => ['/sa/ar//x', RedirectInvalid::NEW_PATH],
        'on our host, with dot-dot words' => ['https://touchwood.test/sa/ar/../../admin', RedirectInvalid::NEW_PATH],
    ]);

    it('takes a new address on an off store: Check Addresses marks it', function () {
        Rx::storeOn('eg', false);

        expect(redirectAdd('/old', '/eg/ar/x'))->toBe('/old');
    });

    it('refuses an old address another line holds', function () {
        redirectAdd('/old', '/sa/ar');

        expect(redirectRefusal(fn () => redirectAdd('/old/', '/sa/en')))->toBe(RedirectInvalid::TAKEN)
            ->and(DB::table('content.redirects')->count())->toBe(1);
    });

    it('is a Super Admin\'s alone', function () {
        Fx::actAsStaff(Fx::staffWith(ContentPermissions::jobs(), ['sa', 'ae', 'eg']));

        expect(fn () => redirectAdd('/old', '/sa/ar'))->toThrow(Unauthorized::class);
    });
});

describe('changing a line', function () {
    it('changes the new address, forgetting what was checked of the old one, and audits by value', function () {
        redirectAdd('/old', '/sa/ar/x');
        DB::table('content.redirects')->update(['check_result' => 'FINE', 'checked_at' => now(), 'visits' => 7]);

        app(ChangeRedirectHandler::class)->handle(new ChangeRedirect('/old', '/old', '/sa/ar/y'));

        expect(DB::table('content.redirects')->first(['new_path', 'check_result', 'visits']))->toEqual((object) ['new_path' => '/sa/ar/y', 'check_result' => null, 'visits' => 7])
            ->and(Fx::audits('content.redirect.changed', RedirectAudit::subject('/old')))->toBe(1);
    });

    it('moves a line to a free old address, keeping its visits, and refuses one taken', function () {
        redirectAdd('/old', '/sa/ar/x');
        redirectAdd('/other', '/sa/ar/y');
        DB::table('content.redirects')->where('old_path', '/old')->update(['visits' => 3]);

        expect(redirectRefusal(fn () => app(ChangeRedirectHandler::class)->handle(new ChangeRedirect('/old', '/other', '/sa/ar/x'))))->toBe(RedirectInvalid::TAKEN);

        app(ChangeRedirectHandler::class)->handle(new ChangeRedirect('/old', '/older', '/sa/ar/x'));

        expect(DB::table('content.redirects')->where('old_path', '/older')->value('visits'))->toBe(3)
            ->and(DB::table('content.redirects')->where('old_path', '/old')->exists())->toBeFalse();
    });

    it('answers an unknown line not found', function () {
        expect(fn () => app(ChangeRedirectHandler::class)->handle(new ChangeRedirect('/nothing', '/nothing', '/sa/ar')))->toThrow(ContentNotFound::class);
    });
});

describe('the rest of a line\'s menu', function () {
    it('deletes a line, audited', function () {
        redirectAdd('/old', '/sa/ar');

        app(DeleteRedirectHandler::class)->handle(new DeleteRedirect('/old'));

        expect(DB::table('content.redirects')->count())->toBe(0)
            ->and(Fx::audits('content.redirect.deleted', RedirectAudit::subject('/old')))->toBe(1);
    });

    it('resets a line\'s count and last visit, audited with the count it had', function () {
        redirectAdd('/old', '/sa/ar');
        DB::table('content.redirects')->update(['visits' => 12, 'last_visit_at' => now()]);

        app(ResetRedirectCountHandler::class)->handle(new ResetRedirectCount('/old'));

        expect(DB::table('content.redirects')->first(['visits', 'last_visit_at']))->toEqual((object) ['visits' => 0, 'last_visit_at' => null])
            ->and(Fx::audits('content.redirect.count_reset', RedirectAudit::subject('/old')))->toBe(1);
    });

    it('sends a forwarding line straight to where it ends, and refuses a line that does not forward', function () {
        redirectAdd('/old', '/sa/ar/products/old-slug');
        expect(redirectRefusal(fn () => app(UseFinalAddressHandler::class)->handle(new UseFinalAddress('/old'))))->toBe(RedirectInvalid::NOT_FORWARDING);

        DB::table('content.redirects')->update(['check_result' => Redirect::FORWARDS, 'check_final_path' => '/sa/ar/products/new-slug', 'checked_at' => now()]);
        app(UseFinalAddressHandler::class)->handle(new UseFinalAddress('/old'));

        expect(DB::table('content.redirects')->first(['new_path', 'check_result', 'check_final_path']))->toEqual((object) ['new_path' => '/sa/ar/products/new-slug', 'check_result' => null, 'check_final_path' => null]);
    });

    it('refuses a final address with dot words, checked as a new one, even past the table\'s CHECK', function () {
        redirectAdd('/old', '/sa/ar/products/old-slug');
        // The table refuses such an address too (redirects_words): dropped here, so only the code stands.
        DB::statement('ALTER TABLE content.redirects DROP CONSTRAINT redirects_words');
        DB::table('content.redirects')->update(['check_result' => Redirect::FORWARDS, 'check_final_path' => '/sa/ar/../../admin', 'checked_at' => now()]);

        expect(redirectRefusal(fn () => app(UseFinalAddressHandler::class)->handle(new UseFinalAddress('/old'))))->toBe(RedirectInvalid::NEW_PATH)
            ->and(DB::table('content.redirects')->value('new_path'))->toBe('/sa/ar/products/old-slug');
    });
});

describe('the list', function () {
    it('lists the most visited first, searched by either address, marking a line a store\'s code took since as can\'t be reached', function () {
        redirectAdd('/blog', '/sa/ar/x');
        redirectAdd('/contact', '/sa/ar/y');
        redirectAdd('/zz', '/ae/en/z');
        DB::table('content.redirects')->where('old_path', '/contact')->update(['visits' => 9, 'check_result' => 'FINE', 'checked_at' => now()]);
        // A store created later whose code is the old address's first word.
        DB::table('platform.stores')->where('code', 'ae')->update(['code' => 'zz']);
        app(StoreDirectory::class)->invalidate();

        $list = app(RedirectsHandler::class)->list(null, null);

        expect(array_column($list['rows'], 'oldPath'))->toBe(['/contact', '/blog', '/zz'])
            ->and(array_column($list['rows'], 'check'))->toBe(['FINE', null, Redirect::CANT_BE_REACHED])
            ->and(array_column(app(RedirectsHandler::class)->list('/sa/ar/y', null)['rows'], 'oldPath'))->toBe(['/contact'])
            ->and($list['checkWaits'])->toBeFalse();
    });

    it('pages by a cursor', function () {
        foreach (range(1, 52) as $n) {
            redirectAdd("/old-{$n}", '/sa/ar');
        }

        $first = app(RedirectsHandler::class)->list(null, null);
        $second = app(RedirectsHandler::class)->list(null, $first['next']);

        expect($first['rows'])->toHaveCount(50)->and($second['rows'])->toHaveCount(2)->and($second['next'])->toBeNull()
            ->and(array_intersect(array_column($first['rows'], 'oldPath'), array_column($second['rows'], 'oldPath')))->toBe([]);
    });
});
