<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Access\Presentation\Http\Middleware\IdentifyCustomer;
use Modules\Access\Presentation\Http\Middleware\UseStorefrontSession;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();

    // Who a storefront request acts as, with the same middleware every storefront page has.
    Route::middleware([UseStorefrontSession::ALIAS, 'web', 'store', IdentifyCustomer::ALIAS])
        ->get('{store}/{locale}/_off_home_who', fn (ActorContext $actors) => response()->json([
            'type' => $actors->current()->type->value,
            'id' => $actors->current()->id,
        ]));
});

/*
| A customer whose home store is off keeps their account: they sign in and shop in the stores that
| are on (access.md §1.1, amendment 53; owner, 2026-10-01).
*/
it('signs a customer of an off store in, in a store that is on', function () {
    $customerId = Fx::customer('omar@example.test', 'eg');
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    $browser = new AdminBrowser;

    $browser->post('/sa/en/account/sign-in', [
        'email' => (string) DB::table('access.customers')->where('id', $customerId)->value('email'),
        'password' => Fx::CUSTOMER_PASSWORD,
    ])->assertRedirect();

    expect($browser->get('/sa/en/_off_home_who')->json())->toBe(['type' => 'CUSTOMER', 'id' => $customerId])
        // Their own store's pages are closed to them, as to everyone.
        ->and($browser->get('/eg/en/_off_home_who')->status())->toBe(404);
});
