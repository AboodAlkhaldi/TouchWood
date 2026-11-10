<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Content\Presentation\Http\RedirectAnswer;
use Modules\Platform\Presentation\Http\InMemoryUnmatchedAddresses;
use Tests\Modules\Content\Support\ContentRedirects as Rx;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The redirects as a visitor meets them (content.md §1.15, §2.3 row L2, §8 #16): an old site's address on a line
| answers 301 to its new page in one hop, **only where nothing else would answer** - from both places Platform asks:
| no route (/flash-deals, /product/x) and an unknown single word matching {store} (/blog, /contact), and the two
| the PR 2 review added (/shop/en/x, /sa/ar/old-page). A real store's /{code} still goes to its home. Counted after
| the answer.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    config(['app.url' => 'https://touchwood.test']);
});

function redirectVisits(string $old): int
{
    return (int) DB::table('content.redirects')->where('old_path', $old)->value('visits');
}

describe('the places asked', function () {
    it('answers 301 to the line\'s new page from every place an address fails', function (string $asked, string $old) {
        Rx::line($old, '/sa/ar/categories/kitchens');

        get("https://touchwood.test{$asked}")->assertStatus(301)->assertHeader('Location', 'https://touchwood.test/sa/ar/categories/kitchens');
    })->with([
        'no route' => ['/flash-deals', '/flash-deals'],
        'no route, a second word that is no language' => ['/product/x', '/product/x'],
        'a single word {store} takes' => ['/blog', '/blog'],
        'another single word' => ['/contact', '/contact'],
        'a first word that names no store, before a language' => ['/shop/en/x', '/shop/en/x'],
    ]);

    it('answers an address under a store no page answers, which no line can name, not found', function () {
        // An old address never begins with a store's code (§1.15), so nothing is found there.
        get('https://touchwood.test/sa/ar/old-page')->assertNotFound();
    });

    it('still sends a real store\'s /{code} to its home', function () {
        Rx::line('/blog', '/sa/ar');

        $response = get('https://touchwood.test/sa');

        expect($response->getStatusCode())->toBe(302)
            ->and((string) $response->headers->get('Location'))->toEndWith('/sa/ar');
    });

    it('answers an address on no line not found: no guessing', function () {
        Rx::line('/flash-deals', '/sa/ar');

        get('https://touchwood.test/flash-deal')->assertNotFound();
        get('https://touchwood.test/Flash-Deals')->assertNotFound();
    });

    it('answers only a read: a form sent to an old address stays not found', function () {
        Rx::line('/flash-deals', '/sa/ar');

        call('POST', 'https://touchwood.test/flash-deals')->assertNotFound();
        call('HEAD', 'https://touchwood.test/flash-deals')->assertStatus(301);
    });
});

describe('the address as asked', function () {
    it('reads an Arabic address decoded, and sends the new one encoded', function () {
        Rx::line('/منتجات/كراسي', '/sa/ar/categories/كراسي');

        get('https://touchwood.test/'.rawurlencode('منتجات').'/'.rawurlencode('كراسي'))
            ->assertStatus(301)
            ->assertHeader('Location', 'https://touchwood.test/sa/ar/categories/'.rawurlencode('كراسي'));
    });

    it('drops the query string and reads the trailing slash as none', function () {
        Rx::line('/flash-deals', '/sa/ar');

        // public/.htaccess forwards /x/ to /x before Laravel sees it (§1.15: one hop first); without it, the same line answers.
        get('https://touchwood.test/flash-deals/?utm_source=old')->assertStatus(301)->assertHeader('Location', 'https://touchwood.test/sa/ar');
    });

    it('reads a line typed with Arabic-Indic digits as the address asked with 0-9', function () {
        Rx::line('/عرض-١٢', '/sa/ar');

        get('https://touchwood.test/'.rawurlencode('عرض-12'))->assertStatus(301);
    });

    it('never puts what was asked into the Location', function () {
        Rx::line('/old', '/sa/ar/pages/new');

        $location = (string) get('https://touchwood.test/old?next=https://evil.test')->headers->get('Location');

        expect($location)->toBe('https://touchwood.test/sa/ar/pages/new');
    });
});

describe('counting', function () {
    it('adds 1 and sets the date after the answer, every visit, bots too', function () {
        Rx::line('/flash-deals', '/sa/ar');
        CarbonImmutable::setTestNow('2026-11-05 08:30:00');

        get('https://touchwood.test/flash-deals', ['User-Agent' => 'Googlebot/2.1'])->assertStatus(301);
        get('https://touchwood.test/flash-deals')->assertStatus(301);

        expect(redirectVisits('/flash-deals'))->toBe(2)
            ->and(CarbonImmutable::parse((string) DB::table('content.redirects')->where('old_path', '/flash-deals')->value('last_visit_at'))->toIso8601String())->toBe('2026-11-05T08:30:00+00:00');
    });

    it('counts nothing until the answer is sent: the write is the application\'s last step', function () {
        Rx::line('/flash-deals', '/sa/ar');
        $answer = app(RedirectAnswer::class)->answer('/flash-deals');

        expect($answer?->getStatusCode())->toBe(301)->and(redirectVisits('/flash-deals'))->toBe(0);

        app()->terminate();

        expect(redirectVisits('/flash-deals'))->toBe(1);
    });

    it('asks one query, by the key, for an address on a line, and the same for one on none', function () {
        Rx::line('/flash-deals', '/sa/ar');
        $addresses = app(InMemoryUnmatchedAddresses::class);

        foreach (['/flash-deals' => 'https://touchwood.test/sa/ar', '/nothing-here' => null] as $path => $location) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $answer = $addresses->answer($path);
            DB::disableQueryLog();

            expect($answer?->headers->get('Location'))->toBe($location)
                ->and(DB::getQueryLog())->toHaveCount(1)
                ->and(DB::getQueryLog()[0]['query'])->toContain('"old_path" = ?');
        }
    });
});
