<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\UpdateCompanyContact\UpdateCompanyContact;
use Modules\B2B\Application\Command\UpdateCompanyContact\UpdateCompanyContactHandler;
use Modules\B2B\Application\Settings\FormRules;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| F11 and F12 in a real browser (b2b.md §4.4, §4.5, amendments 14 to 16): a company account follows
| the line under the header to its page, fills the form — which saves itself as each field is left,
| and says where each field stands —, picks its address from its saved addresses, uploads its papers
| and sends it; then the page and the line say it is under review, with its number. And the states
| the design never drew: not approved, with Apply again; approved and changing its details, warned
| before it sends.
|
| No RefreshDatabase — the suite keeps its data — so every account here is new.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    Cache::flush();
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(B2BFixtures::uploads());
    // The suite keeps its data: a minimum a test raised goes back to where it started.
    companyScreenMinimum(FormRules::TAX_NUMBER_MIN, 5);
});

function companyScreenSignIn(string $customerId, string $locale = 'en'): mixed
{
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    return visit("/sa/{$locale}/sign-in")
        ->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs("/sa/{$locale}");
}

/**
 * A paper under every active document type of the account's open draft, as the customer — and the
 * actor given back after, so the browser's requests go on reading who is signed in.
 */
function companyScreenPapers(string $customerId): void
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Fx::actAsCustomer($customerId);

    try {
        foreach (B2BFixtures::documentTypes() as $type) {
            if ($type->isActive()) {
                app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($type->id(), B2BFixtures::pdf(), 'paper-'.$type->id().'.pdf'));
            }
        }
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/**
 * The company's address picked as the customer picks it — and the actor given back after, so the
 * browser's requests go on reading who is signed in.
 */
function companyScreenMoveTo(string $customerId, string $addressId): void
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Fx::actAsCustomer($customerId);

    try {
        app(UpdateCompanyContactHandler::class)->handle(new UpdateCompanyContact($addressId));
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/** A minimum as an admin sets it, while the page is open (amendment 16(b)). */
function companyScreenMinimum(string $key, int $value): void
{
    Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, null, $value)));
}

/**
 * Whether the expression comes to hold in the page within four seconds: a field's state follows its
 * save, and the plugin's own assertions read the page once.
 */
function companyScreenUntil(mixed $page, string $expression): bool
{
    return $page->script(<<<JS
        () => new Promise((resolve) => {
            // Under the plugin's own five seconds for a script, so it answers rather than times out.
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

/** How a field says it stands: idle, unsaved, saving, saved, invalid or refused. */
function companyScreenLook(string $selector): string
{
    return "document.querySelector('{$selector}')?.dataset.look";
}

it('takes a company from the line under the header through its application to "under review"', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    $addressId = B2BFixtures::savedAddress($customerId);
    $page = companyScreenSignIn($customerId);

    $page->assertSee('Continue Company Application')
        ->click('[data-test="shopper-line"]')
        ->assertPathIs('/sa/en/account/company')
        ->assertSee('Not Sent Yet')
        ->assertSee('Your Application')
        ->assertSee('Usually within two business days')
        ->click('[data-test="start"]')
        ->assertPresent('[data-test="company-form"]');

    // The lifecycle's first step, while it is being filled in (amendment 16(e)).
    expect(companyScreenUntil($page, "document.querySelector('[data-test=step-send]').dataset.state === 'current'"))->toBeTrue();

    // A value the page would not send turns yellow and stays on the page (amendment 16(a)).
    $page->type('#company-name', 'A')
        ->keys('#company-name', 'Tab')
        ->assertSee('At least 2 characters.')
        ->type('#company-cr_number', 'CR#1010')
        ->keys('#company-cr_number', 'Tab')
        ->assertSee('Letters, digits, spaces and dashes only.');

    expect(companyScreenUntil($page, companyScreenLook('#company-name').' === "invalid"'))->toBeTrue()
        ->and(companyScreenUntil($page, companyScreenLook('#company-cr_number').' === "invalid"'))->toBeTrue()
        ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->value('name'))->toBeNull()
        ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->value('cr_number'))->toBeNull();

    // Put right, each is saved as it is left, and says so in green.
    $page->clear('#company-name')
        ->type('#company-name', 'Al Noor Trading')
        ->keys('#company-name', 'Tab')
        ->clear('#company-cr_number')
        ->type('#company-cr_number', '1010123456')
        ->keys('#company-cr_number', 'Tab')
        ->select('#company-type', B2BFixtures::companyTypes()[1]->id())
        ->type('#company-tax_number', '300123456700003')
        ->keys('#company-tax_number', 'Tab')
        ->click('[data-test="pick-address-'.$addressId.'"]')
        ->assertDontSee('At least 2 characters.');

    expect(companyScreenUntil($page, companyScreenLook('#company-name').' === "saved"'))->toBeTrue()
        ->and(companyScreenUntil($page, companyScreenLook('#company-tax_number').' === "saved"'))->toBeTrue()
        ->and(companyScreenUntil($page, "document.querySelector('[data-test=address-picker] [data-test=field-state]').dataset.look === 'saved'"))->toBeTrue()
        ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->value('address_id'))->toBe($addressId);

    // Nothing is complete without the papers: Send waits, and says what is missing (16(d)).
    $page->assertPresent('[data-test="send-missing"]')
        ->assertButtonDisabled('[data-test="send"]');

    // The browser plugin's server drops a multipart body's files (LaravelHttpServer: "@TODO
    // files"), so a file chosen here never arrives — the page then says so beside the paper, as it
    // does for a file too large for the server. The papers go in as the customer instead; the
    // upload itself is MyCompanyPageTest's.
    $page->attach('[data-test="file-'.B2BFixtures::documentTypes()[0]->id().'"]', B2BFixtures::pdf())
        ->assertSee('No file arrived.');
    companyScreenPapers($customerId);

    $page->navigate('/sa/en/account/company')
        ->assertSee('3 of 3 uploaded')
        ->assertValue('#company-name', 'Al Noor Trading')
        ->assertValue('#company-cr_number', '1010123456')
        ->assertMissing('[data-test="send-missing"]');

    $page->click('[data-test="send"]')
        ->assertSee('Application sent')
        ->assertSee('Under Review')
        ->assertSee('Your account is under review')
        ->assertSee('TW-CO-')
        ->assertNoJavaScriptErrors();

    expect(app(CompanyRepository::class)->forCustomer($customerId)?->status()->value)->toBe('PENDING')
        ->and(companyScreenUntil($page, "document.querySelector('[data-test=step-review]').dataset.state === 'current'"))->toBeTrue();
});

it('warns at once, before uploading, that a file is already under another document', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$first, $second] = array_map(static fn ($type): string => $type->id(), B2BFixtures::documentTypes());
    $page = companyScreenSignIn($customerId);
    $page->navigate('/sa/en/account/company')->click('[data-test="start"]')->assertPresent('[data-test="company-form"]');
    companyScreenPapers($customerId);
    $page->navigate('/sa/en/account/company');

    // A file named as the one under the first type, chosen for the second (amendment 16(c)).
    File::ensureDirectoryExists(B2BFixtures::uploads());
    $same = B2BFixtures::uploads()."/paper-{$first}.pdf";
    copy(B2BFixtures::pdf(), $same);
    $held = DB::table('platform.media')->count();

    $page->attach("[data-test=\"file-{$second}\"]", $same)
        ->assertSeeIn("[data-test=\"document-{$second}\"]", 'The same file cannot go into two sections.')
        ->assertNoJavaScriptErrors();

    expect(DB::table('platform.media')->count())->toBe($held);
});

it('reads right to left in Arabic, in the dark, on a phone', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::sent($customerId);
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    // The shop's theme is the person's choice, kept in this browser's cookie rather than read from
    // the phone (frontend.md §2.1) — chosen here before signing in, as StorefrontFrameTest does.
    $page = visit('/sa/ar/sign-in');
    $page->click('[data-test="theme-dark"]');
    // Read until the server's answer is in (lesson 121), as StorefrontFrameTest does.
    expect($page->script("new Promise((done) => { const from = Date.now(); (function look() { const mode = document.documentElement.dataset.mode; if (mode === 'dark' || Date.now() - from > 4000) { done(mode); } else { setTimeout(look, 50); } })(); })"))->toBe('dark');
    $page->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/ar');

    // Narrowed to a phone once signed in. Emulating the phone from the start lost the race between
    // typing and the sign-in page coming to life: what was typed was wiped and nothing was sent.
    $page->resize(375, 812);

    $page->click('[data-test="shopper-line"]')
        ->assertPathIs('/sa/ar/account/company')
        ->assertSee('الطلب قيد المراجعة')
        ->assertSee('طلبك')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.dir'))->toBe('rtl')
        ->and($page->script('document.documentElement.dataset.mode'))->toBe('dark')
        // The date is isolated inside the Arabic sentence, so the browser keeps its order
        // (lib/bidi.ts): innerText keeps the order it was written in either way, so the mark is
        // what is checked.
        ->and($page->script('document.querySelector("[data-test^=application-] summary").innerText.includes("\\u2068" + "2026-")'))->toBeTrue()
        ->and($page->script('document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue();
});

it('shows a company that was not approved why, and applying again marks what to change', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::rejected($customerId, [ApplicationFlag::field(FlaggedField::CrNumber)], [ApplicationRequest::add(strtolower((string) Str::ulid()), RequestKind::Text, 'Who signs for the company?', 1)]);
    $page = companyScreenSignIn($customerId);

    $page->assertSee('Your company application was not approved')
        ->click('[data-test="shopper-line"]')
        ->assertSee('Not Approved')
        ->assertSee('The CR number does not match the certificate.');

    // Decided: the third step, with its result.
    expect(companyScreenUntil($page, "document.querySelector('[data-test=step-decision]').dataset.state === 'current'"))->toBeTrue();

    $page->assertSeeIn('[data-test="step-result"]', 'Not Approved')
        ->click('[data-test="apply-again"]')
        ->assertSee('Marked in the last decision')
        ->assertSee('Who signs for the company?')
        ->assertNoJavaScriptErrors();

    // Applying again starts again at the first step; the marked CR number keeps its red mark and
    // is not shown "Saved" beside it, while a field nobody marked is (amendment 17(f)).
    expect(companyScreenUntil($page, "document.querySelector('[data-test=step-send]').dataset.state === 'current'"))->toBeTrue()
        ->and(companyScreenUntil($page, companyScreenLook('#company-cr_number').' === "idle"'))->toBeTrue()
        ->and(companyScreenUntil($page, companyScreenLook('#company-name').' === "saved"'))->toBeTrue();
});

it('warns an approved company, before it sends a change, that sending it stops its ordering until approved', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $page = companyScreenSignIn($customerId);

    $page->assertDontSee('Continue Company Application')
        ->navigate('/sa/en/account/company')
        ->assertSee('Approved')
        // The bank account is a card of the main column now (amendment 16(e)).
        ->assertSeeIn('[data-test="payment"]', 'Bank transfer is temporarily unavailable')
        ->assertSeeIn('[data-test="step-result"]', 'Approved')
        ->click('[data-test="change"]')
        ->assertPresent('[data-test="change-warning"]')
        ->assertSee('These changes go to our team as a new application.')
        ->assertNoJavaScriptErrors();
});

/*
| After the review of step 6 (amendment 15) and the owner's own use of the page (amendment 16): a
| refusal stays on its own field through the next field's save, Send waits for a complete form,
| "Other" stays chosen until its words are left, a rejected company reinstated since is shown why it
| was rejected, the address is picked from the saved addresses, and the side column follows the
| application.
*/

it('shows a server\'s refusal in red on its own field through the next field\'s save, and holds Send until it is fixed', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $page = companyScreenSignIn($customerId);

    $page->navigate('/sa/en/account/company')
        ->click('[data-test="change"]')
        ->assertPresent('[data-test="company-form"]');

    // Raised after the page was given the rules: the page lets the value go, and the server, which
    // checks today's minimum, refuses it — the one way left for a value to be refused in red.
    companyScreenMinimum(FormRules::TAX_NUMBER_MIN, 20);

    // Two fields left in the same instant, so the second save starts while the first is still out:
    // typed by hand, the first had always come back before the second began, and a lost save was
    // never seen (the review of step 6 lost one this way).
    $page->script(<<<'JS'
        const write = (field, value) => {
            const input = document.querySelector(field);
            input.focus();
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, value);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.blur();
        };
        write('#company-tax_number', '300123456700099');
        write('#company-cr_number', '2020123456');
        JS);

    // Red, and saying why: the refusal's answer gave the page the raised minimum, so it names the
    // reason rather than only "not valid" (amendment 17(g)).
    $page->assertSee('At least 20 characters.');

    expect(companyScreenUntil($page, companyScreenLook('#company-tax_number').' === "refused"'))->toBeTrue()
        ->and(companyScreenUntil($page, companyScreenLook('#company-cr_number').' === "saved"'))->toBeTrue()
        ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->where('state', 'DRAFT')->value('cr_number'))->toBe('2020123456');

    $page->assertPresent('[data-test="send-missing"]')
        ->assertButtonDisabled('[data-test="send"]');

    // Another value short of the new minimum now turns yellow on the page itself, and is not sent.
    $page->clear('#company-tax_number')
        ->type('#company-tax_number', '300123456700088')
        ->keys('#company-tax_number', 'Tab')
        ->assertSee('At least 20 characters.');

    expect(companyScreenUntil($page, companyScreenLook('#company-tax_number').' === "invalid"'))->toBeTrue();

    $page->clear('#company-tax_number')
        ->type('#company-tax_number', '30012345670008812345')
        ->keys('#company-tax_number', 'Tab')
        ->assertDontSee('At least 20 characters.');

    expect(companyScreenUntil($page, companyScreenLook('#company-tax_number').' === "saved"'))->toBeTrue()
        ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->where('state', 'DRAFT')->value('tax_number'))->toBe('30012345670008812345');

    $page->assertMissing('[data-test="send-missing"]')
        ->assertButtonEnabled('[data-test="send"]')
        ->assertNoJavaScriptErrors();
});

it('keeps "Other" chosen until its words are left, and saves them as the type', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $page = companyScreenSignIn($customerId);

    $page->navigate('/sa/en/account/company')
        ->click('[data-test="change"]')
        ->select('#company-type', 'other')
        ->assertPresent('#company-type-other')
        ->assertPresent('[data-test="send-missing"]');

    // Chosen, not yet left: plain — not saved yet, and not yellow (the owner's choice, 17(k)).
    expect(companyScreenUntil($page, companyScreenLook('#company-type-other').' === "unsaved"'))->toBeTrue();

    $page->type('#company-type-other', 'Cooperative society')
        ->keys('#company-type-other', 'Tab')
        ->assertValue('#company-type', 'other')
        ->assertMissing('[data-test="send-missing"]')
        ->assertNoJavaScriptErrors();

    expect(DB::table('b2b.applications')->where('customer_id', $customerId)->where('state', 'DRAFT')->value('company_type_other'))->toBe('Cooperative society');
});

it('shows a rejected company reinstated since why it was rejected, and the reinstatement on its own line', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::rejected($customerId);
    B2BFixtures::suspend($company);
    $company->reinstate(Fx::staff(), Remark::of('reason', 'The tax office confirmed the number.'), CarbonImmutable::now());
    app(CompanyRepository::class)->update($company);
    $page = companyScreenSignIn($customerId);

    $page->navigate('/sa/en/account/company')
        ->assertSeeIn('[data-test="status-reason"]', 'The CR number does not match the certificate.')
        ->assertSeeIn('[data-test="reinstated"]', 'Reinstated: The tax office confirmed the number.')
        // No draft yet: the address can still be changed at once, beside Apply again.
        ->assertPresent('[data-test="address"]')
        ->assertPresent('[data-test="apply-again"]')
        ->assertNoJavaScriptErrors();
});

it('sends a company with no saved address to add one, brings it back, and saves the one it picks at once', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::sent($customerId);
    $page = companyScreenSignIn($customerId);

    $page->navigate('/sa/en/account/company')
        ->assertSee('Under Review')
        ->assertSee('You have no saved addresses yet.')
        ->click('[data-test="add-address"]')
        ->assertPathIs('/sa/en/account')
        ->click('[data-test="add-address-sa"]')
        ->type('#label-sa', 'Head office')
        ->type('#recipient-sa', 'Sara Ali')
        ->type('#phone-sa', '+966512345678')
        ->type('[name="fields[administrative_area]"]', 'Riyadh Region')
        ->type('[name="fields[city]"]', 'Riyadh')
        ->type('[name="fields[district]"]', 'Al Olaya')
        ->type('[name="fields[street]"]', 'Olaya Street')
        ->type('[name="fields[building]"]', '12')
        ->click('[data-test="address-form-sa"] button[type="submit"]')
        // Straight back to the application (access.md amendment 51).
        ->assertPathIs('/sa/en/account/company')
        ->assertSee('Head office');

    $addressId = (string) DB::table('access.addresses')->where('customer_id', $customerId)->value('id');

    $page->click("[data-test=\"pick-address-{$addressId}\"]")
        ->assertSee('Address saved')
        ->assertSeeIn('[data-test="address-kept"]', 'Olaya Street')
        ->assertNoJavaScriptErrors();

    expect(DB::table('b2b.companies')->where('customer_id', $customerId)->value('address_id'))->toBe($addressId)
        // The application waiting keeps what it was sent with.
        ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->value('address'))->toBe("King Fahd Road\nRiyadh");
});

it('hides the lifecycle and the address from a suspended company', function () {
    $suspended = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::approved($suspended);
    B2BFixtures::suspend($company);

    companyScreenSignIn($suspended)
        ->navigate('/sa/en/account/company')
        ->assertSee('You cannot order or change your company details until our team reinstates the account.')
        ->assertMissing('[data-test="steps"]')
        ->assertMissing('[data-test="payment"]')
        ->assertMissing('[data-test="address"]')
        ->assertNoJavaScriptErrors();
});

it('shows a saved address edited since unpicked, with a note, and takes its new text when picked again', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::sent($customerId);
    $addressId = B2BFixtures::savedAddress($customerId, street: 'Olaya Street');
    companyScreenMoveTo($customerId, $addressId);
    // Edited in the address book: the same saved address, another street.
    DB::table('access.addresses')->where('id', $addressId)->update(['fields' => json_encode([
        'administrative_area' => 'Riyadh', 'city' => 'Riyadh', 'district' => 'Al Olaya', 'street' => 'Tahlia Street', 'building' => '7',
    ])]);
    $page = companyScreenSignIn($customerId);

    $page->navigate('/sa/en/account/company')
        ->assertSeeIn('[data-test="address-kept"]', 'Olaya Street')
        ->assertPresent('[data-test="address-changed"]');

    expect($page->script("document.querySelector('[data-test=pick-address-{$addressId}]').checked"))->toBeFalse();

    $page->click("[data-test=\"pick-address-{$addressId}\"]")
        ->assertSee('Address saved')
        ->assertSeeIn('[data-test="address-kept"]', 'Tahlia Street')
        ->assertMissing('[data-test="address-changed"]')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('[data-test=pick-address-{$addressId}]').checked"))->toBeTrue();
});

it('keeps what was typed after a save went out, and shows its own refusal in red (amendment 17(c))', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $page = companyScreenSignIn($customerId);

    $page->navigate('/sa/en/account/company')
        ->click('[data-test="change"]')
        ->assertPresent('[data-test="company-form"]');

    // The page still holds a minimum of 5; the server now asks 20.
    companyScreenMinimum(FormRules::TAX_NUMBER_MIN, 20);

    // Two saves of one field, the second typed before the first came back: the first is taken,
    // the second refused. The first's answer must not put its value back over the second.
    $page->script(<<<'JS'
        const write = (value) => {
            const input = document.querySelector('#company-tax_number');
            input.focus();
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, value);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.blur();
        };
        write('30012345670008812345');
        write('300123456700099');
        JS);

    expect(companyScreenUntil($page, companyScreenLook('#company-tax_number').' === "refused"'))->toBeTrue()
        ->and($page->value('#company-tax_number'))->toBe('300123456700099')
        ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->where('state', 'DRAFT')->value('tax_number'))->toBe('30012345670008812345');

    $page->assertNoJavaScriptErrors();
});
