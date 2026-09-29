<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| F11 and F12 in a real browser (b2b.md §4.4, §4.5, amendment 14): a company account follows the
| line under the header to its page, fills the form — which saves itself as each field is left —,
| uploads its papers and sends it; then the page and the line say it is under review, with its
| number. And the states the design never drew: not approved, with Apply again; approved and
| changing its details, warned before it sends.
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
                app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($type->id(), B2BFixtures::pdf(), 'paper.pdf'));
            }
        }
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

it('takes a company from the line under the header through its application to "under review"', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    $page = companyScreenSignIn($customerId);

    $page->assertSee('Continue your company application')
        ->click('[data-test="shopper-line"]')
        ->assertPathIs('/sa/en/account/company')
        ->assertSee('Not sent yet')
        ->assertSee('What happens after you send')
        ->assertSee('Usually within two business days')
        ->click('[data-test="start"]')
        ->assertPresent('[data-test="company-form"]');

    // Each field saves itself when it is left; a wrong one says so on the spot.
    $page->type('#company-name', 'Al Noor Trading')
        ->keys('#company-name', 'Tab')
        ->type('#company-cr_number', 'CR#1010')
        ->keys('#company-cr_number', 'Tab')
        ->assertSee('The Commercial Registration number is not valid.')
        ->clear('#company-cr_number')
        ->type('#company-cr_number', '1010123456')
        ->keys('#company-cr_number', 'Tab')
        ->select('#company-type', B2BFixtures::companyTypes()[1]->id())
        ->type('#company-tax_number', '300123456700003')
        ->keys('#company-tax_number', 'Tab')
        ->type('#company-address', "King Fahd Road\nRiyadh")
        ->keys('#company-address', 'Tab')
        ->assertDontSee('The Commercial Registration number is not valid.');

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
        ->assertValue('#company-cr_number', '1010123456');

    $page->click('[data-test="send"]')
        ->assertSee('Your application was sent.')
        ->assertSee('Under review')
        ->assertSee('Your account is under review')
        ->assertSee('TW-CO-')
        ->assertNoJavaScriptErrors();

    expect(app(CompanyRepository::class)->forCustomer($customerId)?->status()->value)->toBe('PENDING');
});

it('reads right to left in Arabic, in the dark, on a phone', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::sent($customerId);
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    // The shop's theme is the person's choice, kept in this browser's cookie rather than read from
    // the phone (frontend.md §2.1) — chosen here before signing in, as StorefrontFrameTest does.
    $page = visit('/sa/ar/sign-in');
    $page->click('[data-test="theme"]')->assertSee('فاتح');
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
        ->assertSee('ماذا يحدث بعد التقديم')
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
        ->assertSee('Not approved')
        ->assertSee('The CR number does not match the certificate.')
        ->click('[data-test="apply-again"]')
        ->assertSee('Marked in the last decision')
        ->assertSee('Who signs for the company?')
        ->assertNoJavaScriptErrors();
});

it('warns an approved company, before it sends a change, that sending it stops its ordering until approved', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $page = companyScreenSignIn($customerId);

    $page->assertDontSee('Continue your company application')
        ->navigate('/sa/en/account/company')
        ->assertSee('Approved')
        ->assertSee('Bank transfer is temporarily unavailable')
        ->click('[data-test="change"]')
        ->assertPresent('[data-test="change-warning"]')
        ->assertSee('These changes go to our team as a new application.')
        ->assertNoJavaScriptErrors();
});
