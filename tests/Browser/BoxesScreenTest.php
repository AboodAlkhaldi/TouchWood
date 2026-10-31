<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The Boxes screen in a real browser (shipping.md §4.1 part 3): a Super Admin adds a box through the dialog and
| sees what fits after the padding as it is typed, switches it off, deletes it, and the page fits a phone. The
| suite's database is not reset between runs: the box has this run's own name and is gone at the end.
*/

const BOXES_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('adds a box, shows its room after the padding, switches it off, deletes it, and fits a phone', function () {
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');
    $name = 'Box '.random_int(10000, 99999);

    try {
        $page = visit('/admin/sign-in')
            ->type('#email', $email)
            ->type('#password', BOXES_SCREEN_PASSWORD)
            ->click('button[type="submit"]')
            ->assertPathIs('/admin/sign-in/code')
            ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
            ->click('button[type="submit"]');

        expect(signedInToPanel($page))->toBeTrue();
        $page->navigate('/admin/shipping/boxes?store=sa');
        $page->assertVisible('[data-test="boxes-note"]')->assertVisible('[data-test="padding"]')->assertNoJavaScriptErrors();

        // Add Box: the sizes in mm, the weight in kg, and the room after the padding as it is typed (B4–B6).
        $page->click('[data-test="add-box"]');
        expect(browserSoon($page, "document.querySelector('#box-name') !== null"))->toBeTrue();
        $page->type('#box-name', $name)->type('#box-length', '400')->type('#box-width', '300')->type('#box-height', '200')->type('#box-weight', '2.5');
        $padding = (int) DB::table('platform.settings')->where('key', 'shipping.boxes.padding_mm')->value('value') ?: 10;
        $page->assertSeeIn('[data-test="box-inside"]', (400 - 2 * $padding).' × '.(300 - 2 * $padding).' × '.(200 - 2 * $padding));
        $page->click('[data-test="confirm-box"]');

        expect(browserSoon($page, "document.querySelector('#box-name') === null"))->toBeTrue();
        $id = (string) DB::table('shipping.boxes')->where('name', $name)->value('id');
        $row = "[data-test=\"box-{$id}\"]";
        expect($id)->not->toBe('')
            ->and((int) DB::table('shipping.boxes')->where('id', $id)->value('max_weight_g'))->toBe(2500)
            ->and(browserSoon($page, "document.querySelector('{$row}') !== null"))->toBeTrue();
        $page->assertSeeIn("{$row} [data-test=\"box-name\"]", $name)->assertSeeIn($row, '2.5 kg');

        // Off at once, no question (B7).
        $page->click("[data-test=\"box-switch-{$id}\"]");
        expect(browserSoon($page, "document.querySelector('{$row} [data-test=\"box-off\"]') !== null"))->toBeTrue();

        // A phone: a card each, and nothing scrolls sideways.
        $page->resize(375, 812);
        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
        $page->assertVisible("[data-test=\"box-card-{$id}\"]");
        $page->resize(1280, 800);

        // Delete through its plain dialog (B8).
        $page->click("[data-test=\"box-actions-{$id}\"]");
        $page->click('[data-test="delete-box"]')->click('[data-test="confirm-delete-box"]');
        expect(browserSoon($page, "document.querySelector('{$row}') === null"))->toBeTrue()
            ->and(DB::table('shipping.boxes')->where('id', $id)->exists())->toBeFalse();
        $page->assertNoJavaScriptErrors();
    } finally {
        DB::table('shipping.boxes')->where('name', $name)->delete();
    }
});
