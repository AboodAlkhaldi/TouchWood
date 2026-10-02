<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Domain\Model\Company;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 7 in a real browser (b2b.md §4.6, amendment 19): a reviewer finds a waiting company in the
| list and approves it through its modal; another is rejected with a marked item and a request, then
| suspended from the actions menu and reinstated; and an admin works a store's types page — marks the
| lists reviewed, adds a type and deactivates it from its row's menu.
|
| The feature tests beside these prove what the screens are handed and what each post does; these
| prove a person can use them, Geist's modals and menus included, with no error in the console.
|
| No RefreshDatabase — the suite keeps its data — so every company and type here is new, each company
| is opened by its own address rather than found on a page that fills up (lesson 106), and the types
| page works in the third store, whose lists no other browser test reads.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(B2BFixtures::uploads());
});

/**
 * Signed in at desktop size through the panel's own screens (lesson 118).
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function companyStaffBrowserSignIn(array $permissions, array $stores = ['sa']): mixed
{
    $staffId = Fx::staffWith($permissions, $stores);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    return visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin');
}

/** A new company waiting for a decision in the first store, named for itself. */
function companyStaffBrowserWaiting(): Company
{
    [$company] = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
    DB::table('b2b.companies')->where('id', $company->id())->update(['name' => 'Browser Co '.substr((string) Str::ulid(), -8)]);

    return $company;
}

function companyStaffBrowserStatus(string $companyId): string
{
    return (string) DB::table('b2b.companies')->where('id', $companyId)->value('status');
}

/**
 * Waits, inside the page, until an expression holds — under the plugin's own five seconds for a
 * script — since its assertions read the page once and do not wait (lesson 121).
 */
function companyStaffBrowserUntil(mixed $page, string $expression): bool
{
    return $page->script(<<<JS
        () => new Promise((resolve) => {
            const until = Date.now() + 4000;
            const tick = () => {
                let held = false;
                try { held = Boolean({$expression}); } catch (error) { held = false; }
                if (held || Date.now() > until) { resolve(held); } else { setTimeout(tick, 50); }
            };
            tick();
        })
        JS) === true;
}

function companyStaffBrowserText(string $selector): string
{
    return "(document.querySelector('{$selector}')?.innerText ?? '')";
}

it('finds a waiting company in the list and approves it with a note', function () {
    $company = companyStaffBrowserWaiting();
    $name = (string) DB::table('b2b.companies')->where('id', $company->id())->value('name');
    $page = companyStaffBrowserSignIn([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_REVIEW]);

    // The list, searched for this company: the suite keeps every company the other tests made.
    $page->navigate('/admin/companies?search='.urlencode($name))
        ->assertSee('Companies')
        ->assertSee($name);

    // The pager's range, its placeholders filled the way Laravel fills them: ":to" never eats the
    // start of ":total" (it read "1–1 of 1tal" before lib/t.ts filled the longest first).
    expect(companyStaffBrowserUntil($page, "document.querySelector('nav[aria-label=\"Pages\"] p')?.innerText.trim() === '1–1 of 1'"))->toBeTrue();

    $page->click("[data-test=\"company-{$company->id()}\"]")
        ->assertPathIs("/admin/companies/{$company->id()}");

    expect(companyStaffBrowserUntil($page, companyStaffBrowserText('[data-test="company-status"]').".includes('Pending')"))->toBeTrue();

    $page->click('[data-test="approve"]')
        ->type('#approve-note', 'Welcome aboard.')
        ->click('[data-test="confirm-approve"]');

    expect(companyStaffBrowserUntil($page, companyStaffBrowserText('[data-test="company-status"]').".includes('Approved')"))->toBeTrue()
        ->and(companyStaffBrowserStatus($company->id()))->toBe('APPROVED')
        ->and(DB::table('b2b.applications')->where('company_id', $company->id())->value('decision_reason'))->toBe('Welcome aboard.');

    // Nothing is left to decide, so neither decision is offered any more.
    $page->assertMissing('[data-test="approve"]')
        ->assertMissing('[data-test="reject"]')
        ->assertNoJavaScriptErrors();
});

it('rejects with a marked item and a request, then suspends from the menu and reinstates', function () {
    $company = companyStaffBrowserWaiting();
    $page = companyStaffBrowserSignIn([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_REVIEW, B2BPermissions::COMPANY_SUSPEND]);
    $page->navigate("/admin/companies/{$company->id()}");

    $page->click('[data-test="reject"]');
    // The button waits for a reason (19(g)).
    expect(companyStaffBrowserUntil($page, "document.querySelector('[data-test=\"confirm-reject\"]')?.getAttribute('aria-disabled') === 'true'"))->toBeTrue();

    $page->type('#reject-reason', 'The CR number does not match the certificate.')
        ->click('[data-test="flag-cr_number"]')
        ->click('[data-test="add-request"]')
        ->type('#request-label-0', 'A bank letter confirming the account')
        ->click('[data-test="confirm-reject"]');

    expect(companyStaffBrowserUntil($page, companyStaffBrowserText('[data-test="company-status"]').".includes('Rejected')"))->toBeTrue();
    $application = (string) DB::table('b2b.applications')->where('company_id', $company->id())->value('id');
    expect(DB::table('b2b.application_flags')->where('application_id', $application)->value('field'))->toBe('cr_number')
        ->and(DB::table('b2b.application_requests')->where('application_id', $application)->value('label'))->toBe('A bank letter confirming the account');

    // Suspend lives in the page's actions menu, last (Geist's menu rules).
    $page->click('[data-test="company-actions"]')
        ->click('[data-test="suspend"]')
        ->type('#suspend-reason', 'The tax number is being checked.')
        ->click('[data-test="confirm-suspend"]');

    expect(companyStaffBrowserUntil($page, companyStaffBrowserText('[data-test="company-status"]').".includes('Suspended')"))->toBeTrue()
        ->and(companyStaffBrowserStatus($company->id()))->toBe('SUSPENDED');

    $page->click('[data-test="reinstate"]')
        ->assertSee('The company returns to Rejected. No email is sent.')
        ->type('#reinstate-reason', 'The tax number checks out.')
        ->click('[data-test="confirm-reinstate"]');

    expect(companyStaffBrowserUntil($page, companyStaffBrowserText('[data-test="company-status"]').".includes('Rejected')"))->toBeTrue()
        ->and(companyStaffBrowserStatus($company->id()))->toBe('REJECTED');

    $page->assertNoJavaScriptErrors();
});

it('says why Approve and Open File are disabled, beside each', function () {
    // A company still "Other", read by a reviewer who may not open company papers.
    $company = companyStaffBrowserWaiting();
    DB::table('b2b.companies')->where('id', $company->id())->update(['company_type_id' => null, 'company_type_other' => 'Trading house']);
    $page = companyStaffBrowserSignIn([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_REVIEW]);
    $page->navigate("/admin/companies/{$company->id()}");

    // Disabled, and still there to point at, so the reason can be read (Geist's Button rules). A real
    // pointer over it, not a scripted focus(), which did not reliably reach React here.
    expect(companyStaffBrowserUntil($page, "document.querySelector('[data-test=\"approve\"]')?.getAttribute('aria-disabled') === 'true'"))->toBeTrue();
    $page->hover('[data-test="approve"]');
    expect(companyStaffBrowserUntil($page, "document.body.innerText.includes('Correct the company type to a listed type first.')"))->toBeTrue();

    $paper = B2BFixtures::documentTypes()[0]->id();
    expect(companyStaffBrowserUntil($page, "document.querySelector('[data-test=\"open-{$paper}\"]')?.getAttribute('aria-disabled') === 'true'"))->toBeTrue()
        // Nothing on the page names the file: no link to it, no id in an attribute (amendment 8(c)).
        ->and($page->script("document.querySelectorAll('a[href*=\"/files/\"]').length"))->toBe(0);
    $page->hover("[data-test=\"open-{$paper}\"]");
    expect(companyStaffBrowserUntil($page, "document.body.innerText.includes('Opening company papers is not one of your jobs.')"))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('marks a store\'s lists reviewed, adds a company type, moves its holders off it and deactivates it', function () {
    // The third store's lists, which no other browser test reads; shown as copied again for this run.
    DB::table('b2b.store_type_lists')->where('store_id', Fx::storeId('ae'))->update(['copied_not_reviewed' => true]);
    $english = 'Browser Type '.substr((string) Str::ulid(), -8);
    $holder = companyStaffBrowserWaiting();
    $page = companyStaffBrowserSignIn([
        B2BPermissions::COMPANY_TYPE_CREATE,
        B2BPermissions::COMPANY_TYPE_UPDATE,
        B2BPermissions::COMPANY_TYPE_DEACTIVATE,
        B2BPermissions::DOCUMENT_TYPE_UPDATE,
    ], ['ae']);

    $page->navigate('/admin/company-types')
        ->assertSee('Company Types')
        ->assertVisible('[data-test="copied-notice"]')
        ->assertVisible('[data-test="tab-document"]')
        ->click('[data-test="mark-reviewed"]');

    expect(companyStaffBrowserUntil($page, "document.querySelector('[data-test=\"copied-notice\"]') === null"))->toBeTrue()
        ->and((bool) DB::table('b2b.store_type_lists')->where('store_id', Fx::storeId('ae'))->value('copied_not_reviewed'))->toBeFalse();

    $page->click('[data-test="add-type"]')
        ->type('#type-name-ar', 'نوع '.substr((string) Str::ulid(), -8))
        ->type('#type-name-en', $english)
        ->type('#type-position', '9000')
        ->click('[data-test="confirm-add"]');

    expect(companyStaffBrowserUntil($page, "document.body.innerText.includes('{$english}')"))->toBeTrue();
    $typeId = (string) DB::table('b2b.company_types')->where('store_id', Fx::storeId('ae'))->where('name_en', $english)->value('id');
    expect($typeId)->not->toBe('');

    // A company of this store holds the new type, so deactivating it asks what happens to it.
    DB::table('b2b.companies')->where('id', $holder->id())->update(['home_store_id' => Fx::storeId('ae'), 'company_type_id' => $typeId]);
    $replacement = collect(B2BFixtures::companyTypes('ae'))->first(fn ($type): bool => $type->isActive() && $type->id() !== $typeId)?->id();
    expect($replacement)->not->toBeNull();

    $page->navigate('/admin/company-types')
        ->click("[data-test=\"type-actions-{$typeId}\"]")
        ->click('[data-test="deactivate-type"]')
        ->assertVisible('[data-test="holders-choice"]')
        ->click('#holders-replace')
        ->select('#deactivate-replacement', (string) $replacement)
        ->click('[data-test="confirm-deactivate"]');

    expect(companyStaffBrowserUntil($page, companyStaffBrowserText("[data-test=\"type-{$typeId}\"] [data-test=\"type-state\"]").".includes('Hidden')"))->toBeTrue()
        ->and((bool) DB::table('b2b.company_types')->where('id', $typeId)->value('is_active'))->toBeFalse()
        ->and(DB::table('b2b.companies')->where('id', $holder->id())->value('company_type_id'))->toBe($replacement);

    $page->assertNoJavaScriptErrors();
});
