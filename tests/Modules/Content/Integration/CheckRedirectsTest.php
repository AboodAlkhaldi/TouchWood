<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Content\Application\Command\CheckRedirects\CheckRedirects;
use Modules\Content\Application\Command\CheckRedirects\CheckRedirectsHandler;
use Modules\Content\Application\Command\RequestRedirectCheck\RequestRedirectCheck;
use Modules\Content\Application\Command\RequestRedirectCheck\RequestRedirectCheckHandler;
use Modules\Content\Application\Query\Redirects\RedirectsHandler;
use Modules\Content\Application\Redirects\AddressProbe;
use Modules\Content\Domain\Model\Redirect;
use Modules\Content\Infrastructure\External\HttpAddressProbe;
use Modules\Content\Infrastructure\Queue\CheckRedirectsJob;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\AppAddressProbe;
use Tests\Modules\Content\Support\ContentRedirects as Rx;

use function Pest\Laravel\seed;

/*
| Check Addresses (content.md §1.15, §9.3 #30, §8 #16): each line's new address asked as a visitor asks it, and the
| line marked Fine, Forwards to (with where it ends), Not Found or Store Off. The shop answers through the app itself
| here (AppAddressProbe); the real probe's HTTP is checked on its own below.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['app.url' => 'https://touchwood.test']);
    app()->instance(AddressProbe::class, new AppAddressProbe);
});

/** @return array{0: string|null, 1: string|null} a line's mark and final address */
function checkRedirectsMark(string $old): array
{
    $row = DB::table('content.redirects')->where('old_path', $old)->first(['check_result', 'check_final_path']);

    return [$row?->check_result, $row?->check_final_path];
}

function checkRedirectsRun(?string $after = null): ?string
{
    return Fx::asSystem(fn (): ?string => app(CheckRedirectsHandler::class)->handle(new CheckRedirects($after)));
}

it('marks each line by what its new address answers', function () {
    $p = Px::ready(['60 cm'], Px::category('Kitchens'));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
    Px::onSale('sa', $p['product']);
    $ar = (string) DB::table('catalog.product_slugs')->where('product_id', $p['product'])->where('locale', 'ar')->where('is_current', true)->value('slug');
    $en = (string) DB::table('catalog.product_slugs')->where('product_id', $p['product'])->where('locale', 'en')->where('is_current', true)->value('slug');

    Rx::line('/fine', '/sa/ar');
    // The other language's slug forwards to the product's own (catalog.md amendment 38): a second hop.
    Rx::line('/forwards', "/sa/ar/products/{$en}");
    Rx::line('/gone', '/sa/ar/categories/no-such-category');
    Rx::line('/off', '/eg/ar');
    Rx::storeOn('eg', false);

    expect(checkRedirectsRun())->toBeNull();

    expect(checkRedirectsMark('/fine'))->toBe([Redirect::FINE, null])
        ->and(checkRedirectsMark('/forwards'))->toBe([Redirect::FORWARDS, "/sa/ar/products/{$ar}"])
        ->and(checkRedirectsMark('/gone'))->toBe([Redirect::NOT_FOUND, null])
        ->and(checkRedirectsMark('/off'))->toBe([Redirect::STORE_OFF, null]);

    // An off store's address is not asked.
    $probe = app(AddressProbe::class);
    expect($probe instanceof AppAddressProbe && ! in_array('/eg/ar', $probe->asked, true) && in_array('/sa/ar', $probe->asked, true))->toBeTrue();
});

it('keeps a line\'s last mark when its address gave no answer, and marks nothing changed meanwhile', function () {
    Rx::line('/a', '/sa/ar');
    DB::table('content.redirects')->update(['check_result' => 'NOT_FOUND', 'checked_at' => now()]);
    app()->instance(AddressProbe::class, new class implements AddressProbe
    {
        public function answer(string $path): ?array
        {
            return null;
        }
    });

    checkRedirectsRun();

    expect(checkRedirectsMark('/a'))->toBe([Redirect::NOT_FOUND, null]);
});

it('goes a page of lines a job, each page queuing the next', function () {
    foreach (range(1, CheckRedirectsHandler::PAGE + 1) as $n) {
        Rx::line(sprintf('/old-%03d', $n), '/eg/ar');
    }
    Rx::storeOn('eg', false);

    $next = checkRedirectsRun();

    expect($next)->toBe(sprintf('/old-%03d', CheckRedirectsHandler::PAGE))
        ->and(DB::table('content.redirects')->whereNotNull('check_result')->count())->toBe(CheckRedirectsHandler::PAGE)
        ->and(checkRedirectsRun($next))->toBeNull()
        ->and(DB::table('content.redirects')->whereNull('check_result')->count())->toBe(0);
});

it('queues one check at a time: a second press while one waits queues nothing', function () {
    Rx::superAdmin();
    Queue::fake();

    expect(app(RequestRedirectCheckHandler::class)->handle(new RequestRedirectCheck))->toBeTrue()
        ->and(app(RequestRedirectCheckHandler::class)->handle(new RequestRedirectCheck))->toBeFalse()
        ->and(app(RedirectsHandler::class)->list(null, null)['checkWaits'])->toBeTrue();
    Queue::assertPushed(CheckRedirectsJob::class, 1);

    // Once under way, a press queues another.
    checkRedirectsRun();
    expect(app(RequestRedirectCheckHandler::class)->handle(new RequestRedirectCheck))->toBeTrue();
    Queue::assertPushed(CheckRedirectsJob::class, 2);
});

describe('the real probe', function () {
    it('asks the site\'s own address, follows nothing, and reads a Location back only on this site', function () {
        Http::fake([
            'https://touchwood.test/sa/ar/products/'.rawurlencode('كرسي') => Http::response('', 301, ['Location' => 'https://touchwood.test/sa/ar/products/'.rawurlencode('مقعد')]),
            'https://touchwood.test/sa/ar/away' => Http::response('', 302, ['Location' => 'https://elsewhere.test/x']),
            'https://touchwood.test/sa/ar' => Http::response('ok', 200),
        ]);
        $probe = app(HttpAddressProbe::class);

        expect($probe->answer('/sa/ar/products/كرسي'))->toBe(['status' => 301, 'location' => '/sa/ar/products/مقعد'])
            ->and($probe->answer('/sa/ar/away'))->toBe(['status' => 302, 'location' => null])
            ->and($probe->answer('/sa/ar'))->toBe(['status' => 200, 'location' => null]);
        Http::assertSentCount(3);
    });

    it('answers nothing when the site gives no answer', function () {
        Http::fake(fn () => throw new ConnectionException('down'));

        expect(app(HttpAddressProbe::class)->answer('/sa/ar'))->toBeNull();
    });
});
