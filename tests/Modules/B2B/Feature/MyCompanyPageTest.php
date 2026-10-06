<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\B2B\Application\Settings\BankAccountSettings;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Shared\Application\ActorContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| F11 over HTTP (b2b.md §4.4, §4.5, amendment 14): the company page, the form saving itself, its
| papers and answers, sending, discarding and the address — and the line on every shop page. The
| use cases behind it are B2B's own suites; these are about what the page is given and where each
| refusal lands.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

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
 * A browser signed in as this account.
 */
function myCompanySignedIn(string $customerId): AdminBrowser
{
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');
    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

/**
 * What the company page was given.
 *
 * @return array<string, mixed>
 */
function myCompanyProps(AdminBrowser $browser, string $url = '/sa/en/account/company'): array
{
    $props = [];

    $browser->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $page->component('B2B/Storefront/Company/Company');
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * The field errors a response came back with.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, string>
 */
function myCompanyErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return array_map(static fn (array $messages): string => (string) $messages[0], $errors->getBag('default')->toArray());
    }

    $messages = is_array($errors) ? ($errors['default']['messages'] ?? []) : [];

    return array_map(static fn ($list): string => is_array($list) ? (string) $list[0] : '', is_array($messages) ? $messages : []);
}

/**
 * The store's bank account filled in by a Super Admin — and the session's own actor given back, so
 * the browser's requests go on being the customer's.
 */
function myCompanyBankAccount(): void
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    try {
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32'));
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::BANK, 'sa', 'Al Noor Bank'));
        app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::HOLDER, 'sa', 'TouchWood Trading'));
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

function myCompanyPdf(): UploadedFile
{
    return new UploadedFile(B2BFixtures::pdf(), 'certificate-'.uniqid().'.pdf', 'application/pdf', null, true);
}

it('offers a company account its page from the account, and tells it on every shop page to continue its application', function () {
    $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
    $props = myCompanyProps($browser);

    expect($props['stage'])->toBe('NO_APPLICATION')
        ->and($props['company'])->toBeNull()
        ->and($props['draft'])->toBeNull()
        ->and($props['maxFileBytes'])->toBe(10 * 1024 * 1024)
        ->and($props['phoneConfirmed'])->toBeTrue()
        ->and(array_column($props['companyTypes'], 'nameEn'))->toContain('Limited Liability Company')
        ->and($props['accountMenu']['pages'])->toBe([['key' => 'b2b.company', 'label' => 'Company Account', 'routeName' => 'storefront.company']])
        ->and($props['shopperLines'])->toBe([['text' => 'Continue Company Application', 'routeName' => 'storefront.company', 'tone' => 'info']]);
});

it('says nothing of its own before the email is confirmed, where Access\'s mark already says it', function () {
    $props = myCompanyProps(myCompanySignedIn(B2BFixtures::companyAccount()));

    expect($props['stage'])->toBe('EMAIL_NOT_CONFIRMED')
        ->and($props['shopperLines'])->toBe([]);
});

it('refuses an individual account the page, and offers it neither the page nor a line', function () {
    // Confirmed, so the line is refused for being an individual account and not for the email.
    $customerId = Fx::customer(strtolower((string) Str::ulid()).'@example.test');
    DB::table('access.customers')->where('id', $customerId)->update(['email_verified_at' => now()]);
    $browser = myCompanySignedIn($customerId);

    $browser->get('/sa/en/account/company')->assertForbidden();
    $browser->get('/sa/en/account')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accountMenu.pages', [])
        ->where('shopperLines', []));
});

it('sends a visitor to the store home, as the account pages do', function () {
    (new AdminBrowser)->get('/sa/en/account/company')->assertRedirect();
    (new AdminBrowser)->post('/sa/en/account/company/draft/start')->assertRedirect();

    expect(DB::table('b2b.applications')->count())->toBe(0);
});

it('starts the draft, saves one field at a time, and answers a wrong value on its own field', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    $browser = myCompanySignedIn($customerId);

    $browser->post('/sa/en/account/company/draft/start')->assertRedirect('/sa/en/account/company');
    $browser->post('/sa/en/account/company/draft', ['name' => 'Al Noor Trading'])->assertRedirect();
    $refused = $browser->post('/sa/en/account/company/draft', ['cr_number' => 'CR#1010']);
    $browser->post('/sa/en/account/company/draft', ['tax_number' => '300123456700003']);

    $props = myCompanyProps($browser);

    expect(myCompanyErrors($refused))->toHaveKey('cr_number')
        ->and(myCompanyErrors($refused))->not->toHaveKey('form')
        ->and($props['stage'])->toBe('DRAFT_OPEN')
        ->and($props['draft']['values']['name'])->toBe('Al Noor Trading')
        ->and($props['draft']['values']['crNumber'])->toBeNull()
        // A field left out of a save keeps its value.
        ->and($props['draft']['values']['taxNumber'])->toBe('300123456700003')
        ->and($props['shopperLines'][0]['text'])->toBe('Finish and Send Company Application');
});

it('keeps "Other" as the company\'s own words, and a listed type as its id', function () {
    $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
    $browser->post('/sa/en/account/company/draft/start');

    $browser->post('/sa/en/account/company/draft', ['company_type_id' => null, 'company_type_other' => 'Cooperative society']);
    $other = myCompanyProps($browser)['draft']['values'];
    $browser->post('/sa/en/account/company/draft', ['company_type_id' => B2BFixtures::companyTypes()[0]->id(), 'company_type_other' => null]);
    $listed = myCompanyProps($browser)['draft']['values'];

    expect([$other['companyTypeId'], $other['companyTypeOther']])->toBe([null, 'Cooperative society'])
        ->and([$listed['companyTypeId'], $listed['companyTypeOther']])->toBe([B2BFixtures::companyTypes()[0]->id(), null]);
});

it('answers "Other" words it refuses on the type, the one field the page has for them', function () {
    $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
    $browser->post('/sa/en/account/company/draft/start');

    $refused = $browser->post('/sa/en/account/company/draft', ['company_type_id' => null, 'company_type_other' => str_repeat('a', 101)]);

    expect(array_keys(myCompanyErrors($refused)))->toBe(['company_type']);
});

it('refuses an empty address in the page\'s own language', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $browser = myCompanySignedIn($customerId);

    $refused = $browser->post('/sa/ar/account/company/address', ['address_id' => '']);

    expect(myCompanyErrors($refused))->toHaveKey('address')
        ->and(myCompanyErrors($refused)['address'])->toBe(trans('b2b::errors.invalid_company_attribute.detail', ['attribute' => trans('b2b::errors.fields.address', [], 'ar')], 'ar'));
});

it('takes a paper under its type, opens it through a link that expires, and removes it', function () {
    $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
    $browser->post('/sa/en/account/company/draft/start');
    $typeId = B2BFixtures::documentTypes()[0]->id();

    $browser->post("/sa/en/account/company/draft/documents/{$typeId}", ['file' => myCompanyPdf()])->assertRedirect()->assertSessionHasNoErrors();
    $documents = myCompanyProps($browser)['draft']['documents'];
    $opened = $browser->get("/sa/en/account/company/files/{$documents[0]['mediaId']}");
    $browser->post("/sa/en/account/company/draft/documents/{$typeId}/remove")->assertRedirect();

    expect($documents)->toHaveCount(1)
        ->and($documents[0]['documentTypeId'])->toBe($typeId)
        ->and($opened->status())->toBe(302)
        // The private disk's own link, which runs out (the fake disk writes its expiry as "expiration").
        ->and((string) $opened->headers->get('Location'))->toContain('/private/')
        ->and((string) $opened->headers->get('Location'))->toContain('expiration=')
        ->and(myCompanyProps($browser)['draft']['documents'])->toBe([]);
});

it('says so beside the paper when no file arrives, and answers "not found" for a file that is not the account\'s', function () {
    $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
    $browser->post('/sa/en/account/company/draft/start');
    $typeId = B2BFixtures::documentTypes()[0]->id();

    $nothing = $browser->post("/sa/en/account/company/draft/documents/{$typeId}", []);
    $someoneElses = B2BFixtures::privateFile();

    expect(myCompanyErrors($nothing))->toHaveKey("documents.{$typeId}");
    $browser->get("/sa/en/account/company/files/{$someoneElses}")->assertNotFound();
});

it('answers a paper Platform refuses beside that paper, not at the top of the form', function () {
    $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
    $browser->post('/sa/en/account/company/draft/start');
    $typeId = B2BFixtures::documentTypes()[0]->id();
    $text = UploadedFile::fake()->createWithContent('notes.txt', 'Not a paper at all.');

    $refused = $browser->post("/sa/en/account/company/draft/documents/{$typeId}", ['file' => $text]);

    expect(myCompanyErrors($refused))->toHaveKey("documents.{$typeId}")
        ->and(myCompanyErrors($refused))->not->toHaveKey('form')
        ->and(myCompanyProps($browser)['draft']['documents'])->toBe([]);
});

it('sends a complete draft: the company is under review, the application has its number, and the line says so', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    $browser = myCompanySignedIn($customerId);
    $browser->post('/sa/en/account/company/draft/start');
    $browser->post('/sa/en/account/company/draft', [
        'name' => 'Al Noor Trading',
        'company_type_id' => B2BFixtures::companyTypes()[0]->id(),
        'cr_number' => '1010123456',
        'tax_number' => '300123456700003',
        'address_id' => B2BFixtures::savedAddress($customerId),
    ]);

    foreach (B2BFixtures::documentTypes() as $type) {
        $browser->post("/sa/en/account/company/draft/documents/{$type->id()}", ['file' => myCompanyPdf()]);
    }

    $sent = $browser->post('/sa/en/account/company/draft/send')->assertRedirect('/sa/en/account/company');
    $props = myCompanyProps($browser);

    expect(AdminBrowser::flashed($sent, 'status'))->toBe('Application sent')
        ->and($props['company']['status'])->toBe('PENDING')
        ->and($props['draft'])->toBeNull()
        ->and($props['history'])->toHaveCount(1)
        ->and($props['history'][0]['reference'])->toMatch('/^TW-CO-\d{2}-0001$/')
        ->and($props['shopperLines'][0]['tone'])->toBe('warn');
});

it('fills and saves a draft without a confirmed phone, refuses its send at the top of the form, and says why (amendment 26(a))', function () {
    $customerId = B2BFixtures::verifiedCompanyAccountWithoutPhone();
    $browser = myCompanySignedIn($customerId);

    expect(myCompanyProps($browser)['phoneConfirmed'])->toBeFalse();

    $browser->post('/sa/en/account/company/draft/start');
    $browser->post('/sa/en/account/company/draft', [
        'name' => 'Al Noor Trading',
        'company_type_id' => B2BFixtures::companyTypes()[0]->id(),
        'cr_number' => '1010123456',
        'tax_number' => '300123456700003',
        'address_id' => B2BFixtures::savedAddress($customerId),
    ]);

    foreach (B2BFixtures::documentTypes() as $type) {
        $browser->post("/sa/en/account/company/draft/documents/{$type->id()}", ['file' => myCompanyPdf()]);
    }

    $refused = $browser->post('/sa/en/account/company/draft/send');
    $props = myCompanyProps($browser);

    expect(AdminBrowser::formError($refused))->toBe('Couldn\'t send the application: your phone number isn\'t confirmed. Confirm it on your account\'s Phone page, then send.')
        ->and($props['company'])->toBeNull()
        ->and($props['draft']['values']['name'])->toBe('Al Noor Trading');
});

it('refuses an incomplete send at the top of the form, and sends nothing', function () {
    $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
    $browser->post('/sa/en/account/company/draft/start');

    $refused = $browser->post('/sa/en/account/company/draft/send');

    expect(myCompanyErrors($refused))->toHaveKey('form')
        ->and(DB::table('b2b.applications')->whereNotNull('reference')->count())->toBe(0);
});

it('shows a rejected company why, and applying again brings what it marked and asked for to answer', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    $requestId = strtolower((string) Str::ulid());
    B2BFixtures::rejected($customerId, [ApplicationFlag::field(FlaggedField::CrNumber)], [ApplicationRequest::add($requestId, RequestKind::Text, 'Who signs for the company?', 1)]);
    $browser = myCompanySignedIn($customerId);

    $before = myCompanyProps($browser);
    $browser->post('/sa/en/account/company/draft/start');
    $browser->post("/sa/en/account/company/draft/answers/{$requestId}", ['text' => 'The owner, Sara Ali.'])->assertRedirect();
    $after = myCompanyProps($browser);

    expect($before['company']['status'])->toBe('REJECTED')
        ->and($before['company']['statusReason'])->toBe('The CR number does not match the certificate.')
        ->and($before['shopperLines'][0]['tone'])->toBe('bad')
        ->and($after['draft']['flags'])->toBe([['field' => 'cr_number', 'documentTypeId' => null]])
        ->and($after['draft']['requests'][0]['label'])->toBe('Who signs for the company?')
        ->and($after['draft']['answers'])->toBe([['requestId' => $requestId, 'text' => 'The owner, Sara Ali.', 'mediaId' => null]]);
});

it('shows an approved company its account to transfer to only while bank transfer is on, and says nothing on the shop pages', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $browser = myCompanySignedIn($customerId);

    $off = myCompanyProps($browser);
    myCompanyBankAccount();
    $on = myCompanyProps($browser);

    expect($off['company']['status'])->toBe('APPROVED')
        ->and($off['bankAccount'])->toBeNull()
        ->and($off['shopperLines'])->toBe([])
        ->and($on['bankAccount'])->toBe(['iban' => 'GB82 WEST 1234 5698 7654 32', 'bank' => 'Al Noor Bank', 'holder' => 'TouchWood Trading']);
});

it('lets an approved company change its address at once, and start a change of its details without losing its line', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $addressId = B2BFixtures::savedAddress($customerId, street: 'Olaya Street');
    $browser = myCompanySignedIn($customerId);

    $saved = $browser->post('/sa/en/account/company/address', ['address_id' => $addressId])->assertRedirect();
    $browser->post('/sa/en/account/company/draft/start');
    $props = myCompanyProps($browser);

    expect(AdminBrowser::flashed($saved, 'status'))->toBe('Address saved')
        ->and($props['company']['details']['address'])->toBe(B2BFixtures::addressText($addressId))
        ->and($props['company']['details']['addressId'])->toBe($addressId)
        ->and($props['company']['status'])->toBe('APPROVED')
        ->and($props['draft']['values']['address'])->toBe(B2BFixtures::addressText($addressId))
        ->and($props['draft']['values']['addressId'])->toBe($addressId)
        // Still approved and still ordering: nothing to say on the shop pages (amendment 14(c)).
        ->and($props['shopperLines'])->toBe([]);
});

it('tells a suspended company why on every shop page, refuses it a draft, and lets it discard the one it has', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::approved($customerId);
    $browser = myCompanySignedIn($customerId);
    $browser->post('/sa/en/account/company/draft/start');
    B2BFixtures::suspend(app(CompanyRepository::class)->forCustomer($customerId, Fx::storeId('sa')) ?? $company);

    $props = myCompanyProps($browser);
    $refused = $browser->post('/sa/en/account/company/draft', ['name' => 'A new name']);
    $browser->post('/sa/en/account/company/draft/discard')->assertRedirect('/sa/en/account/company');

    expect($props['company']['status'])->toBe('SUSPENDED')
        ->and($props['shopperLines'])->toBe([['text' => 'Your company account is suspended: Suspended while the tax number is checked.', 'routeName' => 'storefront.company', 'tone' => 'bad']])
        ->and(myCompanyErrors($refused))->toHaveKey('form')
        ->and(myCompanyProps($browser)['draft'])->toBeNull();
});

it('writes every time in the home store\'s clock', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 21:30', 'UTC'));
    B2BFixtures::sent($customerId);
    $browser = myCompanySignedIn($customerId);

    $props = myCompanyProps($browser);
    CarbonImmutable::setTestNow();

    // 21:30 UTC is half past midnight the next day in Riyadh.
    expect($props['company']['statusChangedAt'])->toBe('2026-10-01T00:30:00+03:00')
        ->and($props['history'][0]['submittedAt'])->toBe('2026-10-01T00:30:00+03:00');
});

describe('what the page is told, and where each refusal lands (amendment 16)', function () {
    it('hands the page every field\'s rules and the account\'s saved addresses, any store\'s but nobody else\'s', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $home = B2BFixtures::savedAddress($customerId, 'sa', 'Head office');
        $branch = B2BFixtures::savedAddress($customerId, 'eg', 'Branch');
        B2BFixtures::savedAddress(B2BFixtures::companyAccount(), 'sa', 'Somebody else');
        $props = myCompanyProps(myCompanySignedIn($customerId));

        expect(array_keys($props['formRules']))->toBe(['name', 'cr_number', 'tax_number', 'company_type_other', 'answer', 'note'])
            ->and($props['formRules']['name'])->toBe(['min' => 2, 'max' => 200, 'oneLine' => true, 'characters' => null])
            ->and(array_column($props['savedAddresses'], 'id'))->toBe([$home, $branch])
            ->and(array_column($props['savedAddresses'], 'storeNameEn'))->toBe(['Saudi Arabia', 'Egypt'])
            ->and($props['savedAddresses'][0]['label'])->toBe('Head office')
            ->and($props['savedAddresses'][0]['formatted'])->toBe(B2BFixtures::addressText($home))
            ->and($props['savedAddresses'][0]['isComplete'])->toBeTrue();
    });

    it('tells the page which saved address the draft holds, and each paper\'s own file name', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $addressId = B2BFixtures::savedAddress($customerId);
        $browser = myCompanySignedIn($customerId);
        $browser->post('/sa/en/account/company/draft/start');
        $typeId = B2BFixtures::documentTypes()[0]->id();

        $browser->post('/sa/en/account/company/draft', ['address_id' => $addressId])->assertSessionHasNoErrors();
        $browser->post("/sa/en/account/company/draft/documents/{$typeId}", ['file' => new UploadedFile(B2BFixtures::pdf(), 'cr-certificate.pdf', 'application/pdf', null, true)]);
        $draft = myCompanyProps($browser)['draft'];

        expect($draft['values']['addressId'])->toBe($addressId)
            ->and($draft['values']['address'])->toBe(B2BFixtures::addressText($addressId))
            ->and($draft['documents'][0]['fileName'])->toBe('cr-certificate.pdf');
    });

    it('refuses the same file under a second document type on that type, in the page\'s language', function () {
        $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
        $browser->post('/sa/ar/account/company/draft/start');
        [$first, $second] = array_map(static fn ($type): string => $type->id(), B2BFixtures::documentTypes());
        $scan = static fn (): UploadedFile => new UploadedFile(B2BFixtures::pdf(), 'scan.pdf', 'application/pdf', null, true);

        $browser->post("/sa/ar/account/company/draft/documents/{$first}", ['file' => $scan()])->assertSessionHasNoErrors();
        $refused = $browser->post("/sa/ar/account/company/draft/documents/{$second}", ['file' => $scan()]);

        expect(myCompanyErrors($refused))->toBe(["documents.{$second}" => trans('b2b::errors.duplicate_document_file.detail', [], 'ar')])
            ->and(myCompanyProps($browser)['draft']['documents'])->toHaveCount(1);
    });

    it('refuses on the address a pick that is not the account\'s own', function () {
        // Saved before the browser signs in: saved after, the fixture's address went to the signed-in
        // account instead, and the pick was taken (savedAddress).
        $theirs = B2BFixtures::savedAddress(B2BFixtures::companyAccount());
        $browser = myCompanySignedIn(B2BFixtures::verifiedCompanyAccount());
        $browser->post('/sa/en/account/company/draft/start');

        $refused = $browser->post('/sa/en/account/company/draft', ['address_id' => $theirs]);

        expect(array_keys(myCompanyErrors($refused)))->toBe(['address'])
            ->and(myCompanyProps($browser)['draft']['values']['address'])->toBeNull();
    });

    it('brings a company back to its page once it saves an address from there, and nobody else (access.md amendment 51)', function (string $accountType, string $landsOn) {
        $customerId = Fx::customer(strtolower((string) Str::ulid()).'@example.test', 'sa', $accountType);
        $browser = myCompanySignedIn($customerId);

        $browser->post('/sa/en/account/addresses', [
            'store_id' => Fx::storeId('sa'),
            'label' => 'Head office',
            'recipient_name' => 'Sara Ali',
            'phone' => '+966512345678',
            'fields' => ['administrative_area' => 'Riyadh', 'city' => 'Riyadh', 'district' => 'Al Olaya', 'street' => 'King Fahd Road', 'building' => '7'],
            'return' => 'b2b.company',
        ])->assertRedirect($landsOn);
    })->with([
        'a company account' => ['company', '/sa/en/account/company'],
        'an individual account' => ['individual', '/sa/en/account?tab=addresses'],
    ]);
});

/*
| A company per store over HTTP (b2b.md amendments 18-20): the page of the store being browsed, the
| companies elsewhere, "Apply in this store", and the line that offers it.
*/
it('shows the company of the store being browsed, names the one elsewhere, and offers applying in this store', function () {
    $customerId = B2BFixtures::verifiedCompanyAccount();
    B2BFixtures::approved($customerId);
    $browser = myCompanySignedIn($customerId);

    $props = myCompanyProps($browser, '/eg/en/account/company');
    $line = [];
    $browser->get('/eg/en')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$line) {
        $line = $page->toArray()['props']['shopperLines'] ?? [];
    });
    $saudiLine = [];
    $browser->get('/sa/en')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$saudiLine) {
        $saudiLine = $page->toArray()['props']['shopperLines'] ?? [];
    });

    expect($props['company'])->toBeNull()
        ->and($props['storeNameEn'])->toBe('Egypt')
        ->and($props['elsewhere'])->toHaveCount(1)
        ->and($props['elsewhere'][0]['storeNameEn'])->toBe('Saudi Arabia')
        ->and($props['elsewhere'][0]['status'])->toBe('APPROVED')
        ->and($props['prefill']['name'] ?? null)->toBe('Al Noor Trading')
        ->and($props['prefill']['fromStoreNameEn'] ?? null)->toBe('Saudi Arabia')
        ->and(array_column($line, 'text'))->toBe(['Apply in this store to order here: each store approves its own companies.'])
        // Approved there: nothing to say in its own store.
        ->and($saudiLine)->toBe([]);

    $browser->post('/eg/en/account/company/draft/start')->assertRedirect();

    expect(DB::table('b2b.applications')->where('customer_id', $customerId)->where('store_id', Fx::storeId('eg'))->value('name'))->toBe('Al Noor Trading')
        ->and(myCompanyProps($browser, '/eg/en/account/company')['prefill'])->toBeNull();
});
