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
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/address-formats');

    // What a field is called lives in an input's value, not in the page's text, so the screen is
    // read for its own words and the fields are read where they are actually kept (a first go
    // asserted "Region" as text and failed on a page that was drawing it perfectly, 2026-09-25).
    // The store is named in the screen's own store select - the panel's header no longer names a
    // store (access.md amendment 64), and an option's text is not page text to the browser.
    $page->assertSee('Address Forms')
        ->assertSee('Name in the System')
        ->assertSee('How It Is Printed');
    expect($page->script('document.querySelector("[data-test=\"store\"]").selectedOptions[0].textContent'))->toBe('Saudi Arabia');

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

/*
| Every box checks itself as it is typed (frontend.md §1.7): a letter in a field's longest allowed
| length is said under it at once - kept in the box, not dropped - and Save stays out of reach until
| the box holds a number from 1 to 1,000 (AddressField::LENGTH_MAX). Nothing is saved here: the
| store's form is shared by the other tests.
*/
it('says a letter typed in a field\'s longest length at once, and keeps Save out of reach until it is right', function () {
    $staffId = Fx::staffWith([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa'], RoleLevel::Admin);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', ADDRESS_FORMAT_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/address-formats');

    $was = (string) $page->script('document.getElementById("length-0").value');
    expect($was)->toMatch('/^\d+$/');

    $page->clear('#length-0')->typeSlowly('#length-0', '8x', 20);

    expect(browserUntil($page, 'document.getElementById("length-0-error")?.textContent === "Longest allowed takes numbers only."'))->toBeTrue()
        ->and($page->script('document.getElementById("length-0").value'))->toBe('8x')
        ->and($page->script('document.querySelector(\'[data-test="save"]\').getAttribute("aria-disabled")'))->toBe('true');

    $page->type('#length-0', '1001');
    expect(browserUntil($page, 'document.getElementById("length-0-error")?.textContent === "Longest allowed is from 1 to 1,000."'))->toBeTrue();

    $page->type('#length-0', $was);
    expect(browserUntil($page, 'document.getElementById("length-0-error") === null && document.querySelector(\'[data-test="save"]\').getAttribute("aria-disabled") === null'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

/**
 * The keys of a store's address form, in the order it asks for them.
 *
 * @return list<string>
 */
function addressFormatKeys(string $storeCode): array
{
    $fields = json_decode((string) DB::table('access.store_address_formats')->where('store_id', Fx::storeId($storeCode))->value('fields'), true);

    return array_values(array_map(static fn (array $field): string => (string) $field['key'], is_array($fields) ? $fields : []));
}

/**
 * The form's keys once the save has landed: the click only sends it, so the order is read again
 * for up to five seconds rather than at once.
 *
 * @param  list<string>  $expected
 * @return list<string>
 */
function addressFormatKeysSoon(string $storeCode, array $expected): array
{
    for ($tries = 0; $tries < 50; $tries++) {
        if (addressFormatKeys($storeCode) === $expected) {
            break;
        }

        usleep(100_000);
    }

    return addressFormatKeys($storeCode);
}

/**
 * Keys pressed on an element one at a time, a moment apart.
 *
 * @param  list<string>  $keys
 */
function addressFormatKeysPressed(mixed $page, string $selector, array $keys): void
{
    foreach ($keys as $key) {
        $page->keys($selector, [$key]);
        $page->wait(0.3);
    }
}

it('moves a field by its handle from the keyboard, and the country asks for them in the new order', function () {
    // The UAE's form, which no other test changes, and which this test puts back as it found it.
    $staffId = Fx::staffWith([AccessPermissions::ADDRESS_FORMAT_UPDATE], ['ae'], RoleLevel::Admin);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
    $before = addressFormatKeys('ae');

    expect(count($before))->toBeGreaterThan(1);

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', ADDRESS_FORMAT_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/address-formats?store=ae');

    // dnd-kit's keyboard drag, as shadcn's dashboard-01 sets it up: Space picks the first field up,
    // the down arrow moves it one place, Space puts it down (owner, 2026-10-03: drag handles).
    // A breath between keys, as a person leaves: dnd-kit measures the fields after each one, and
    // keys sent in the same instant arrive before it has (found by stepping through it).
    addressFormatKeysPressed($page, '[data-test="drag-0"]', ['Space', 'ArrowDown', 'Space']);
    $page->assertNoJavaScriptErrors();

    expect($page->script('document.querySelector("#key-0").value'))->toBe($before[1])
        ->and($page->script('document.querySelector("#key-1").value'))->toBe($before[0]);

    $page->click('[data-test="save"]')->assertNoJavaScriptErrors();

    $moved = [$before[1], $before[0], ...array_slice($before, 2)];

    expect(addressFormatKeysSoon('ae', $moved))->toBe($moved);

    // And back, the same way, so the form is as it was.
    addressFormatKeysPressed($page, '[data-test="drag-1"]', ['Space', 'ArrowUp', 'Space']);
    $page->click('[data-test="save"]')->assertNoJavaScriptErrors();

    expect(addressFormatKeysSoon('ae', $before))->toBe($before);
});
