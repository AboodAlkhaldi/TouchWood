<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| O7, the Subscribers page (ops.md §4.15): a plain visit gets the page and JSON stays for whoever asks; a removal the
| page sends goes back with its words. ScreensReadsTest and WrittenRoutesTest keep the JSON answers.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function subscribersScreenStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.5.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @return array<string, string> */
function subscribersScreenInertia(): array
{
    return ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_REFERER' => url('/admin/newsletter/subscribers')];
}

function subscribersScreenGuest(string $email = 'guest@example.test'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.subscribers')->insert([
        'id' => $id, 'store_id' => Fx::storeId('sa'), 'email' => $email, 'locale' => 'en', 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
    ]);

    return $id;
}

/**
 * The words a form went back with.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
function subscribersScreenErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

it('gives a plain visit the page, and JSON to whoever asks', function () {
    $guest = subscribersScreenGuest();
    $browser = subscribersScreenStaff(Fx::staffWith(['ops.newsletter.manage'], ['sa', 'ae'], RoleLevel::Admin));

    $browser->get('/admin/newsletter/subscribers?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/Subscribers')
        ->where('storeCode', 'sa')
        ->has('stores', 2)
        ->has('subscribers', 1)
        ->where('subscribers.0.id', $guest)
        ->where('next', null)
        ->where('translations', fn (Collection $words): bool => $words->has('ops::admin_subscribers.title') && $words->has('ops::errors.subscriber_not_found.detail')));

    expect(array_keys($browser->get('/admin/newsletter/subscribers?store=sa', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json()))->toBe(['subscribers', 'next']);
});

it('refuses the page to someone without ops.newsletter.manage', function () {
    subscribersScreenStaff(Fx::staffWith(['ops.report.view'], ['sa']))->get('/admin/newsletter/subscribers')->assertForbidden();
});

it('removes a guest from the page and goes back with its toast', function () {
    $guest = subscribersScreenGuest();
    $browser = subscribersScreenStaff(Fx::staffWith(['ops.newsletter.manage'], ['sa'], RoleLevel::Admin));

    $answer = $browser->post("/admin/newsletter/subscribers/{$guest}/remove", [], subscribersScreenInertia())->assertRedirect('/admin/newsletter/subscribers');

    expect(AdminBrowser::flashed($answer, 'status'))->toBe(__('ops::admin_subscribers.remove.toast'))
        ->and(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeFalse();
});

it('says a removal refused in the form\'s words, and keeps the empty answer for a request no page sent', function () {
    $staff = Fx::staffWith(['ops.newsletter.manage'], ['sa'], RoleLevel::Admin);
    $browser = subscribersScreenStaff($staff);
    $gone = strtolower((string) Str::ulid());

    expect(subscribersScreenErrors($browser->post("/admin/newsletter/subscribers/{$gone}/remove", [], subscribersScreenInertia()))['form'][0])->toBe(__('ops::errors.subscriber_not_found.detail'));

    $browser->post('/admin/newsletter/subscribers/'.subscribersScreenGuest('other@example.test').'/remove')->assertNoContent();
});

it('offers the menu entry only to whoever may use it', function () {
    $menu = static fn (string $staffId): array => array_column(array_merge(...array_column(subscribersScreenStaff($staffId)->get('/admin')->viewData('page')['props']['menu'], 'entries')), 'key');

    expect(array_values(array_intersect($menu(Fx::staffWith(['ops.newsletter.manage'], ['sa'], RoleLevel::Admin)), ['subscribers'])))->toBe(['subscribers'])
        ->and(array_values(array_intersect($menu(Fx::staffWith(['ops.report.view'], ['sa'])), ['subscribers'])))->toBe([]);
});
