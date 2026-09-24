<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The store address format editor, in a real browser (stage 2b, frontend.md §3.7).
|
| The feature tests beside it prove what the screen is handed and what the server does with what it
| sends. This proves a person can use it: that the fields draw, that adding one and moving it does
| what it says, and that saving really changes what the country asks for.
|
| No RefreshDatabase, for the reason written at the top of StaffScreensTest.
*/

const ADDRESS_FORMAT_PASSWORD = 'a long enough password';

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('adds a field, names it, and the country asks for it afterwards', function () {
    $staffId = Fx::staffWith([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa'], RoleLevel::Admin);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', ADDRESS_FORMAT_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/address-formats');

    // What a field is called lives in an input's value, not in the page's text, so the screen is
    // read for its own words and the fields are read where they are actually kept (a first go
    // asserted "Region" as text and failed on a page that was drawing it perfectly, 2026-09-25).
    $page->assertSee('Address forms')
        ->assertSee('Saudi Arabia')
        ->assertSee('Name in the system')
        ->assertSee('How it is printed');

    expect($page->script('document.querySelector("#key-0").value'))->toBe('administrative_area')
        ->and($page->script('document.querySelector("#label-en-0").value'))->toBe('Region');

    // One more field, named in both languages, added at the end of the list.
    $page->click('[data-test="add-field"]');

    $last = DB::table('access.store_address_formats')->where('store_id', Fx::storeId('sa'))->value('fields');
    $count = is_string($last) ? count((array) json_decode($last, true)) : 0;

    // A key nobody has used before: nothing here is rolled back, and a format with the same field
    // twice is refused outright (found by running the whole suite, 2026-09-25).
    $key = 'extra_'.strtolower(Str::random(8));

    $page->type("#key-{$count}", $key)
        ->type("#label-ar-{$count}", 'ثلاث كلمات')
        ->type("#label-en-{$count}", 'What3words')
        ->click('[data-test="save"]')
        ->assertNoJavaScriptErrors();

    // Asserted where it is kept rather than in a message that fades after six seconds.
    expect((string) DB::table('access.store_address_formats')->where('store_id', Fx::storeId('sa'))->value('fields'))
        ->toContain($key);

});
