<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Presentation\Http\InMemoryUnmatchedAddresses;
use Modules\Platform\Public\Contracts\UnmatchedAddress;
use Modules\Platform\Public\Contracts\UnmatchedAddresses;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Modules\Platform\Support\RecordingUnmatchedAddress;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\seed;

/*
| An address nobody answers (content.md §2.3 row L2, platform.md L2): asked from two places - a read no
| route matched, and a single word that names no store - and answered "not found" exactly as before when
| nobody redirects. Content's lookup arrives in its PR 4; a recording stand-in answers here.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    RecordingUnmatchedAddress::reset();
});

/** Laravel's own not-found page, as a read no route matched has always been answered. */
function unmatchedNotFoundPage(string $path): string
{
    return (string) app(ExceptionHandler::class)->render(Request::create($path), new NotFoundHttpException)->getContent();
}

/**
 * The keys of the session the request was answered with.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function unmatchedSessionKeys(TestResponse $response): array
{
    $request = $response->baseRequest;

    if (! $request instanceof Request || ! $request->hasSession()) {
        throw new LogicException('The request had no session.');
    }

    return array_map(strval(...), array_keys($request->session()->all()));
}

function unmatchedRegisterRecorder(): void
{
    app(UnmatchedAddresses::class)->register(RecordingUnmatchedAddress::class);
}

describe('with nobody registered', function () {
    it('answers a read no route matched not found, the page byte for byte as before', function (string $path) {
        $response = get($path);

        $response->assertNotFound();
        expect($response->getContent())->toBe(unmatchedNotFoundPage($path));
    })->with(['/flash-deals', '/product/x', '/blog/old/post', '/'.rawurlencode('مدونة')]);

    it('answers a single word that names no store not found, as before', function (string $path) {
        get($path)->assertNotFound();
    })->with(['/blog', '/contact']);
});

describe('with an answer registered', function () {
    it('sends a read no route matched where the answer says', function () {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = ['/flash-deals' => '/sa/ar/offers', '/product/x' => '/sa/ar/products/x'];

        get('/flash-deals')->assertStatus(301)->assertRedirect('/sa/ar/offers');
        get('/product/x')->assertStatus(301)->assertRedirect('/sa/ar/products/x');

        expect(RecordingUnmatchedAddress::$asked)->toBe(['/flash-deals', '/product/x']);
    });

    it('sends a single word that names no store where the answer says', function () {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = ['/blog' => '/sa/ar/blog'];

        get('/blog')->assertStatus(301)->assertRedirect('/sa/ar/blog');
        get('/contact')->assertNotFound();

        expect(RecordingUnmatchedAddress::$asked)->toBe(['/blog', '/contact']);
    });

    it('gives the answer the decoded address without its query string', function () {
        unmatchedRegisterRecorder();

        get('/'.rawurlencode('مدونة').'?utm_source=google')->assertNotFound();
        get('/old/page')->assertNotFound();

        expect(RecordingUnmatchedAddress::$asked)->toBe(['/مدونة', '/old/page']);
    });

    it('keeps a trailing slash, and names no address that is not UTF-8', function () {
        // Laravel's test client trims a trailing slash before sending, and public/.htaccess redirects one
        // away on a real server first, so the address is read from the request itself here.
        expect(InMemoryUnmatchedAddresses::pathOf(Request::create('/'.rawurlencode('مدونة').'/?x=1')))->toBe('/مدونة/')
            ->and(InMemoryUnmatchedAddresses::pathOf(Request::create('/old/page/')))->toBe('/old/page/')
            ->and(InMemoryUnmatchedAddresses::pathOf(Request::create('/%C3%28')))->toBeNull()
            ->and(InMemoryUnmatchedAddresses::pathOf(Request::create('/%00x')))->toBeNull()
            ->and(InMemoryUnmatchedAddresses::pathOf(Request::create('/%5Cevil.com')))->toBeNull()
            ->and(InMemoryUnmatchedAddresses::pathOf(Request::create('/a%09b')))->toBeNull();
    });

    it('answers not found, the page byte for byte as before, when the answer has nothing', function () {
        unmatchedRegisterRecorder();

        $response = get('/flash-deals');

        $response->assertNotFound();
        expect($response->getContent())->toBe(unmatchedNotFoundPage('/flash-deals'))
            ->and(RecordingUnmatchedAddress::$asked)->toBe(['/flash-deals']);
    });

    it('never asks about an address under a reserved first segment', function (string $path) {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = [$path => '/sa/ar'];

        get($path);

        expect(RecordingUnmatchedAddress::$asked)->toBe([]);
    })->with(['/admin/x', '/admin/sign-in/x', '/build/x.js', '/api/x', '/up/x', '/storage/x', '/ADMIN/sign-in/x']);

    it('never asks about anything but a read', function () {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = ['/flash-deals' => '/sa/ar/offers'];

        post('/flash-deals')->assertNotFound();
        call('DELETE', '/flash-deals')->assertNotFound();

        expect(RecordingUnmatchedAddress::$asked)->toBe([]);
    });

    it('never asks about a read of an address only a POST answers, which stays "method not allowed"', function () {
        unmatchedRegisterRecorder();
        // Payments' notice address: its first segment reserved by nobody, so only the 404 rule keeps it out.
        $path = '/payments/notice/'.strtolower((string) Str::ulid()).'/test';
        RecordingUnmatchedAddress::$targets = [$path => '/sa/ar'];

        get($path)->assertStatus(405);

        expect(RecordingUnmatchedAddress::$asked)->toBe([]);
    });

    it('never asks about a page that exists', function () {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = ['/sa' => '/elsewhere', '/sa/ar' => '/elsewhere'];

        // A real store's code still goes to its home, as before, and its home is its home.
        get('/sa')->assertRedirect('/sa/ar');
        get('/sa/ar')->assertOk();

        expect(RecordingUnmatchedAddress::$asked)->toBe([]);
    });

    it('asks about an address under a store that no page answers, and keeps the shop\'s own not-found when nobody answers (PR 2 review)', function () {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = ['/sa/ar/old-page' => '/sa/ar/pages/about'];

        get('/sa/ar/old-page')->assertStatus(301)->assertRedirect('/sa/ar/pages/about');
        get('/sa/en/nothing-here')->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('Storefront/Error'));
        // A form sent there is never asked about.
        post('/sa/ar/old-page')->assertNotFound();

        expect(RecordingUnmatchedAddress::$asked)->toBe(['/sa/ar/old-page', '/sa/en/nothing-here']);
    });

    it('asks about a first word that names no store before a language, never about an off store (PR 2 review)', function () {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = ['/shop/en/x' => '/sa/en', '/eg/ar/x' => '/sa/ar'];
        DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);
        app(StoreDirectory::class)->invalidate();

        get('/shop/en/x')->assertStatus(301)->assertRedirect('/sa/en');
        // An off store is a 404 as if it were never there: nothing is asked.
        get('/eg/ar/x')->assertNotFound();
        post('/shop/en/x')->assertNotFound();

        expect(RecordingUnmatchedAddress::$asked)->toBe(['/shop/en/x']);
    });

    it('never asks about an address holding a control character or a backslash, which stays a plain 404', function (string $path) {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = [rawurldecode($path) => '/sa/ar'];

        $response = get($path);

        $response->assertNotFound();
        expect(RecordingUnmatchedAddress::$asked)->toBe([])
            ->and($response->headers->get('Location'))->toBeNull();
    })->with(['/%00x', '/%5Cevil.com', '/sa/ar/%5Cevil.com', '/shop/en/%01x', '/a%0Db']);

    it('asks about a HEAD as about a GET', function () {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = ['/flash-deals' => '/sa/ar/offers'];

        call('HEAD', '/flash-deals')->assertStatus(301);

        expect(RecordingUnmatchedAddress::$asked)->toBe(['/flash-deals']);
    });

    it('writes nothing to the shop\'s session when it answers a single word', function () {
        unmatchedRegisterRecorder();
        RecordingUnmatchedAddress::$targets = ['/blog' => '/sa/ar/blog'];

        // What the shop's session middleware itself keeps, on the same route by the branch that never asks.
        $framework = unmatchedSessionKeys(get('/sa')->assertRedirect('/sa/ar'));
        $afterAnswer = unmatchedSessionKeys(get('/blog')->assertRedirect('/sa/ar/blog'));
        $afterNotFound = unmatchedSessionKeys(get('/contact')->assertNotFound());

        sort($framework);
        sort($afterAnswer);
        sort($afterNotFound);

        expect($framework)->not->toBe([])
            ->and($afterAnswer)->toBe($framework)
            ->and($afterNotFound)->toBe($framework);
    });
});

describe('the registry', function () {
    it('refuses a class that is no answer, and one registered twice', function () {
        $registry = app(InMemoryUnmatchedAddresses::class);

        expect(fn () => $registry->register(stdClass::class))->toThrow(LogicException::class, 'does not implement');

        $registry->register(RecordingUnmatchedAddress::class);
        expect(fn () => $registry->register(RecordingUnmatchedAddress::class))->toThrow(LogicException::class, 'twice');
    });

    it('asks the answers in the order registered, the first answer winning', function () {
        $first = new class implements UnmatchedAddress
        {
            public function answer(string $path): ?RedirectResponse
            {
                return $path === '/one' ? new RedirectResponse('/first', 301) : null;
            }
        };
        app()->instance($first::class, $first);
        $registry = app(InMemoryUnmatchedAddresses::class);
        $registry->register($first::class);
        $registry->register(RecordingUnmatchedAddress::class);
        RecordingUnmatchedAddress::$targets = ['/one' => '/second', '/two' => '/second'];

        expect($registry->answer('/one')?->getTargetUrl())->toEndWith('/first')
            ->and($registry->answer('/two')?->getTargetUrl())->toEndWith('/second')
            ->and(RecordingUnmatchedAddress::$asked)->toBe(['/two']);
    });
});
