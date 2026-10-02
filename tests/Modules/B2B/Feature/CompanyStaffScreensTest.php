<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequest;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequestHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Shared\Application\ActorContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 7: the staff company screens over HTTP (b2b.md §4.6, amendment 21) — the list, one
| company, and every button behind it: refused without the job, a company of another store answered
| as one that does not exist, the happy path, and a value the domain refuses said beside its field.
|
| The use cases have their own tests (B2BStaffDecisionsTest, B2BStaffCompanyViewTest); these are about
| the screens. Signed in through the panel's own door, as a person reaches a screen.
|
| Every helper is named after this file's subject: a Pest file's functions are global. Companies are
| made before the browser signs in (lesson 120).
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
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function companyStaffScreens(array $permissions, array $stores = ['sa']): AdminBrowser
{
    return companyStaffScreensSignIn(Fx::staffWith($permissions, $stores));
}

function companyStaffScreensSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.8.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

/**
 * A company whose first application waits for a decision, in the 'sa' store — or moved to another.
 */
function companyStaffScreensWaiting(string $storeCode = 'sa', string $name = 'Al Noor Trading'): Company
{
    $customerId = B2BFixtures::verifiedCompanyAccount();
    [$company] = B2BFixtures::sent($customerId);
    DB::table('b2b.companies')->where('id', $company->id())->update(['name' => $name, 'home_store_id' => Fx::storeId($storeCode)]);

    return app(CompanyRepository::class)->find($company->id()) ?? throw new LogicException;
}

function companyStaffScreensStatus(string $companyId): string
{
    return (string) DB::table('b2b.companies')->where('id', $companyId)->value('status');
}

/**
 * The errors a redirect carries, by field.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function companyStaffScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

function companyStaffScreensNotFound(): string
{
    return trans('b2b::errors.company_not_found.detail', [], 'en');
}

describe('the company list', function () {
    it('shows the companies of the reader\'s stores, waiting ones first, and offers only their stores to filter by', function () {
        $mine = companyStaffScreensWaiting('sa', 'Al Noor Trading');
        companyStaffScreensWaiting('eg', 'Cairo Supplies');
        $browser = companyStaffScreens([B2BPermissions::COMPANY_VIEW], ['sa']);

        $browser->get('/admin/companies')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('B2B/Admin/Companies/Index')
                ->has('companies', 1)
                ->where('companies.0.id', $mine->id())
                ->where('companies.0.name', 'Al Noor Trading')
                ->where('companies.0.status', 'PENDING')
                ->where('companies.0.storeName', 'Saudi Arabia')
                ->where('companies.0.waitingSince', fn (?string $at): bool => $at !== null && str_ends_with($at, '+03:00'))
                ->where('total', 1)
                ->where('perPage', 25)
                ->where('statuses', ['PENDING', 'APPROVED', 'REJECTED', 'SUSPENDED'])
                ->has('stores', 1)
                ->where('stores.0.id', Fx::storeId('sa'))
            );
    });

    it('shows a Super Admin every store\'s companies, and every store to filter by', function () {
        companyStaffScreensWaiting('sa');
        companyStaffScreensWaiting('eg', 'Cairo Supplies');
        $browser = companyStaffScreensSignIn(Fx::staff(superAdmin: true));

        $browser->get('/admin/companies')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('companies', 2)->where('total', 2)->has('stores', 3));
    });

    it('narrows by status, by a search and by store', function () {
        companyStaffScreensWaiting('sa', 'Al Noor Trading');
        $cairo = companyStaffScreensWaiting('eg', 'Cairo Supplies');
        $browser = companyStaffScreens([B2BPermissions::COMPANY_VIEW], ['sa', 'eg']);

        $browser->get('/admin/companies?search=cairo')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('companies', 1)->where('companies.0.id', $cairo->id())->where('search', 'cairo'));

        $browser->get('/admin/companies?store='.Fx::storeId('eg'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('companies', 1)->where('storeId', Fx::storeId('eg'))->has('stores', 2));

        $browser->get('/admin/companies?status=approved')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('companies', 0)->where('status', 'APPROVED'));
    });

    it('refuses a store the reader does not cover, and a status that is not one', function () {
        $browser = companyStaffScreens([B2BPermissions::COMPANY_VIEW], ['sa']);

        $browser->get('/admin/companies?store='.Fx::storeId('eg'))->assertForbidden();
        $browser->get('/admin/companies?status=closed')->assertStatus(422);
    });

    it('refuses someone who may view companies nowhere', function () {
        $browser = companyStaffScreens([B2BPermissions::COMPANY_REVIEW, B2BPermissions::COMPANY_TYPE_UPDATE], ['sa']);

        $browser->get('/admin/companies')->assertForbidden();
    });
});

describe('one company', function () {
    it('shows the company, its holder and the applications it sent, and only the buttons the reader may press', function () {
        $company = companyStaffScreensWaiting();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_REVIEW], ['sa']);

        $browser->get("/admin/companies/{$company->id()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('B2B/Admin/Companies/Show')
                ->where('company.id', $company->id())
                ->where('company.values.name', 'Al Noor Trading')
                ->where('company.status', 'PENDING')
                ->where('company.storeName', 'Saudi Arabia')
                ->where('company.mayOrder', false)
                ->where('holder.emailVerified', true)
                ->has('applications', 1)
                ->where('applications.0.state', 'SUBMITTED')
                ->where('applications.0.reference', fn (string $reference): bool => str_starts_with($reference, 'TW-CO-'))
                ->has('applications.0.documents', 3)
                // The file's own name goes only to whoever may open it (21(e)).
                ->where('applications.0.documents.0.fileName', '')
                // Nor the file's id: a reader without the private-files permission is never told
                // which file exists (amendment 8(c)).
                ->where('applications.0.documents.0.mediaId', null)
                ->where('actions.mayApprove', true)
                ->where('actions.mayReject', true)
                ->where('actions.approveRefusal', null)
                ->where('actions.maySuspend', false)
                ->where('actions.mayReinstate', false)
                ->where('actions.mayCorrectType', false)
                ->where('actions.mayOpenDocuments', false)
                ->where('typeChoices', [])
            );
    });

    it('says an answer is a file, giving its id and name only to whoever may open papers (amendment 8(c))', function () {
        // Made before any browser signs in, as the account itself (lesson 120).
        $customerId = B2BFixtures::verifiedCompanyAccount();
        $requestId = strtolower((string) Str::ulid());
        [$company] = B2BFixtures::rejected($customerId, [], [ApplicationRequest::add($requestId, RequestKind::File, 'A bank letter', 1)]);
        $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($requestId, path: B2BFixtures::pdf(), originalFilename: 'bank.pdf'));
        app(SubmitApplicationHandler::class)->handle(new SubmitApplication);
        // And no longer acting as the customer, or the panel's sign-in is refused as theirs.
        if ($previous !== null) {
            app()->scoped(ActorContext::class, $previous);
            app()->forgetScopedInstances();
        }

        companyStaffScreens([B2BPermissions::COMPANY_VIEW])->get("/admin/companies/{$company->id()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.0.answers.0.requestId', $requestId)
                ->where('applications.0.answers.0.label', 'A bank letter')
                ->where('applications.0.answers.0.isFile', true)
                ->where('applications.0.answers.0.mediaId', null)
                ->where('applications.0.answers.0.fileName', null)
            );

        companyStaffScreens([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_DOCUMENT_VIEW])->get("/admin/companies/{$company->id()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.0.answers.0.isFile', true)
                ->where('applications.0.answers.0.mediaId', fn (?string $id): bool => is_string($id) && strlen($id) === 26)
                ->where('applications.0.answers.0.fileName', 'bank.pdf')
            );
    });

    it('names the papers and offers the type correction to whoever holds those jobs', function () {
        $company = companyStaffScreensWaiting();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_DOCUMENT_VIEW, B2BPermissions::COMPANY_CORRECT_TYPE], ['sa']);

        $browser->get("/admin/companies/{$company->id()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.0.documents.0.fileName', fn (string $name): bool => str_starts_with($name, 'certificate-'))
                ->where('applications.0.documents.0.mediaId', fn (?string $id): bool => is_string($id) && strlen($id) === 26)
                ->where('actions.mayOpenDocuments', true)
                ->where('actions.mayCorrectType', true)
                ->where('actions.mayChooseOther', true)
                ->has('typeChoices', count(B2BFixtures::companyTypes()))
                ->where('actions.mayApprove', false)
            );
    });

    it('answers a company of another store, and one that does not exist, as not found', function () {
        $theirs = companyStaffScreensWaiting('eg');
        $browser = companyStaffScreens([B2BPermissions::COMPANY_VIEW], ['sa']);

        $browser->get("/admin/companies/{$theirs->id()}")->assertNotFound();
        $browser->get('/admin/companies/01jzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
    });

    it('refuses someone who may view companies nowhere', function () {
        $company = companyStaffScreensWaiting();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_REVIEW], ['sa']);

        $browser->get("/admin/companies/{$company->id()}")->assertForbidden();
    });
});

describe('approving and rejecting', function () {
    it('approves with a note, back on the company with the toast', function () {
        $company = companyStaffScreensWaiting();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_REVIEW]);

        $approved = $browser->post("/admin/companies/{$company->id()}/approve", ['note' => 'Welcome aboard.']);

        $approved->assertRedirect("/admin/companies/{$company->id()}");
        expect(AdminBrowser::flashed($approved, 'status'))->toBe('Company approved')
            ->and(companyStaffScreensStatus($company->id()))->toBe('APPROVED')
            ->and(DB::table('b2b.applications')->where('company_id', $company->id())->value('decision_reason'))->toBe('Welcome aboard.');
    });

    it('says a note the domain refuses beside the note, and approves nothing', function () {
        $company = companyStaffScreensWaiting();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_REVIEW]);

        $refused = $browser->post("/admin/companies/{$company->id()}/approve", ['note' => str_repeat('a', 1001)]);

        expect(companyStaffScreensErrors($refused))->toHaveKey('note')
            ->and(companyStaffScreensStatus($company->id()))->toBe('PENDING');
    });

    it('rejects with a reason, marked items and requests', function () {
        $company = companyStaffScreensWaiting();
        $paper = B2BFixtures::documentTypes()[0]->id();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_REVIEW]);

        $rejected = $browser->post("/admin/companies/{$company->id()}/reject", [
            'reason' => 'The CR number does not match the certificate.',
            'flags' => ['cr_number'],
            'documents' => [$paper],
            'requests' => [['kind' => 'FILE', 'label' => 'A bank letter confirming the account']],
        ]);

        $rejected->assertRedirect("/admin/companies/{$company->id()}");
        $application = (string) DB::table('b2b.applications')->where('company_id', $company->id())->value('id');

        expect(AdminBrowser::flashed($rejected, 'status'))->toBe('Application rejected')
            ->and(companyStaffScreensStatus($company->id()))->toBe('REJECTED')
            ->and(DB::table('b2b.application_flags')->where('application_id', $application)->pluck('field')->filter()->values()->all())->toBe(['cr_number'])
            ->and(DB::table('b2b.application_flags')->where('application_id', $application)->whereNotNull('document_type_id')->value('document_type_id'))->toBe($paper)
            ->and(DB::table('b2b.application_requests')->where('application_id', $application)->value('label'))->toBe('A bank letter confirming the account');
    });

    it('says a missing reason beside the reason, and an empty request beside the requests, and rejects nothing', function () {
        $company = companyStaffScreensWaiting();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_REVIEW]);

        expect(companyStaffScreensErrors($browser->post("/admin/companies/{$company->id()}/reject", ['reason' => ''])))->toHaveKey('reason')
            ->and(companyStaffScreensErrors($browser->post("/admin/companies/{$company->id()}/reject", [
                'reason' => 'Unreadable papers.',
                'requests' => [['kind' => 'TEXT', 'label' => '']],
            ])))->toHaveKey('requests')
            ->and(companyStaffScreensStatus($company->id()))->toBe('PENDING');
    });

    it('refuses a decision from someone who may only look, and from staff of another store as not found', function () {
        $company = companyStaffScreensWaiting();
        $looker = companyStaffScreens([B2BPermissions::COMPANY_VIEW]);

        $refused = $looker->post("/admin/companies/{$company->id()}/approve", []);
        expect($refused->getStatusCode())->toBe(302)
            ->and(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(companyStaffScreensStatus($company->id()))->toBe('PENDING');

        $stranger = companyStaffScreens([B2BPermissions::COMPANY_REVIEW], ['eg']);
        expect(AdminBrowser::formError($stranger->post("/admin/companies/{$company->id()}/reject", ['reason' => 'Not ours.'])))->toBe(companyStaffScreensNotFound())
            ->and(companyStaffScreensStatus($company->id()))->toBe('PENDING');
    });
});

describe('suspending and reinstating', function () {
    it('suspends with a reason and reinstates with one, back to the status it had', function () {
        $company = companyStaffScreensWaiting();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_SUSPEND]);

        $suspended = $browser->post("/admin/companies/{$company->id()}/suspend", ['reason' => 'The tax number is being checked.']);
        $suspended->assertRedirect("/admin/companies/{$company->id()}");
        expect(AdminBrowser::flashed($suspended, 'status'))->toBe('Company suspended')
            ->and(companyStaffScreensStatus($company->id()))->toBe('SUSPENDED');

        $browser->get("/admin/companies/{$company->id()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.statusBeforeSuspension', 'PENDING')
                ->where('actions.maySuspend', false)
                ->where('actions.mayReinstate', true)
            );

        $reinstated = $browser->post("/admin/companies/{$company->id()}/reinstate", ['reason' => 'The tax number checks out.']);
        expect(AdminBrowser::flashed($reinstated, 'status'))->toBe('Company reinstated')
            ->and(companyStaffScreensStatus($company->id()))->toBe('PENDING');
    });

    it('says a missing reason beside the reason, refuses without the job, and answers another store as not found', function () {
        $company = companyStaffScreensWaiting();

        expect(companyStaffScreensErrors(companyStaffScreens([B2BPermissions::COMPANY_SUSPEND])->post("/admin/companies/{$company->id()}/suspend", ['reason' => ' '])))->toHaveKey('reason')
            ->and(AdminBrowser::formError(companyStaffScreens([B2BPermissions::COMPANY_REVIEW])->post("/admin/companies/{$company->id()}/suspend", ['reason' => 'No.'])))->not->toBeNull()
            ->and(AdminBrowser::formError(companyStaffScreens([B2BPermissions::COMPANY_SUSPEND], ['eg'])->post("/admin/companies/{$company->id()}/suspend", ['reason' => 'No.'])))->toBe(companyStaffScreensNotFound())
            ->and(companyStaffScreensStatus($company->id()))->toBe('PENDING');
    });
});

describe('correcting the type', function () {
    it('moves the company to another listed type of its store', function () {
        $company = companyStaffScreensWaiting();
        $target = B2BFixtures::companyTypes()[0]->id();
        $browser = companyStaffScreens([B2BPermissions::COMPANY_CORRECT_TYPE]);

        $corrected = $browser->post("/admin/companies/{$company->id()}/type", ['type_id' => $target]);

        $corrected->assertRedirect("/admin/companies/{$company->id()}");
        expect(AdminBrowser::flashed($corrected, 'status'))->toBe('Company type corrected')
            ->and(DB::table('b2b.companies')->where('id', $company->id())->value('company_type_id'))->toBe($target);
    });

    it('says a deactivated type is refused until confirmed, beside the type, and needs the job of activating types as well', function () {
        $company = companyStaffScreensWaiting();
        $gone = B2BFixtures::companyTypes()[4];
        B2BFixtures::deactivate($gone);
        $corrector = companyStaffScreens([B2BPermissions::COMPANY_CORRECT_TYPE]);

        expect(companyStaffScreensErrors($corrector->post("/admin/companies/{$company->id()}/type", ['type_id' => $gone->id()])))->toHaveKey('type')
            ->and(AdminBrowser::formError($corrector->post("/admin/companies/{$company->id()}/type", ['type_id' => $gone->id(), 'confirm_reactivation' => true])))->not->toBeNull()
            ->and(DB::table('b2b.companies')->where('id', $company->id())->value('company_type_id'))->not->toBe($gone->id());

        $both = companyStaffScreens([B2BPermissions::COMPANY_CORRECT_TYPE, B2BPermissions::COMPANY_TYPE_DEACTIVATE]);
        $both->post("/admin/companies/{$company->id()}/type", ['type_id' => $gone->id(), 'confirm_reactivation' => '1'])
            ->assertRedirect("/admin/companies/{$company->id()}");

        expect(DB::table('b2b.companies')->where('id', $company->id())->value('company_type_id'))->toBe($gone->id())
            ->and((bool) DB::table('b2b.company_types')->where('id', $gone->id())->value('is_active'))->toBeTrue();
    });

    it('says "Other" for an approved company beside the type, and answers another store as not found', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$approved] = B2BFixtures::approved($customerId);
        $waiting = companyStaffScreensWaiting('eg');
        $browser = companyStaffScreens([B2BPermissions::COMPANY_CORRECT_TYPE], ['sa']);

        expect(companyStaffScreensErrors($browser->post("/admin/companies/{$approved->id()}/type", ['other' => 'Trading house'])))->toHaveKey('type')
            ->and(AdminBrowser::formError($browser->post("/admin/companies/{$waiting->id()}/type", ['other' => 'Trading house'])))->toBe(companyStaffScreensNotFound());
    });
});

describe('opening a paper', function () {
    it('hands a 30-minute link to whoever may open papers, and audits the opening', function () {
        $company = companyStaffScreensWaiting();
        $mediaId = (string) DB::table('b2b.application_documents')
            ->join('b2b.applications', 'b2b.applications.id', '=', 'b2b.application_documents.application_id')
            ->where('b2b.applications.company_id', $company->id())
            ->value('media_id');
        $browser = companyStaffScreens([B2BPermissions::COMPANY_DOCUMENT_VIEW]);

        $opened = $browser->get("/admin/companies/{$company->id()}/files/{$mediaId}");

        // Away to Platform's signed link (its own tests cover the link): never the file itself here.
        expect($opened->getStatusCode())->toBe(302)
            ->and((string) $opened->headers->get('Location'))->not->toBe('')
            ->and((string) $opened->headers->get('Location'))->not->toContain('/admin/companies/')
            ->and(Fx::audits('b2b.company.document_opened', $company->id()))->toBe(1);
    });

    it('refuses someone without the job, and answers another store\'s company and an unknown file as not found', function () {
        $company = companyStaffScreensWaiting();
        $theirs = companyStaffScreensWaiting('eg');
        $mediaId = (string) DB::table('b2b.application_documents')
            ->join('b2b.applications', 'b2b.applications.id', '=', 'b2b.application_documents.application_id')
            ->where('b2b.applications.company_id', $theirs->id())
            ->value('media_id');

        companyStaffScreens([B2BPermissions::COMPANY_VIEW])->get("/admin/companies/{$company->id()}/files/{$mediaId}")->assertForbidden();

        $opener = companyStaffScreens([B2BPermissions::COMPANY_DOCUMENT_VIEW], ['sa']);
        $opener->get("/admin/companies/{$theirs->id()}/files/{$mediaId}")->assertNotFound();
        $opener->get("/admin/companies/{$company->id()}/files/{$mediaId}")->assertNotFound();

        expect(Fx::audits('b2b.company.document_opened'))->toBe(0);
    });
});

describe('the menu', function () {
    it('offers each screen in the Companies group to whoever holds its job, and nothing to anyone else', function () {
        $menu = static function (AdminBrowser $browser): array {
            $offered = [];

            $browser->get('/admin')->assertInertia(function (AssertableInertia $page) use (&$offered) {
                /** @var list<array{key: string, entries: list<array{module: string, key: string, href: string}>}> $groups */
                $groups = $page->toArray()['props']['menu'];

                foreach ($groups as $group) {
                    foreach ($group['entries'] as $entry) {
                        if ($entry['module'] === 'b2b') {
                            $offered[] = $group['key'].'/'.$entry['key'].' '.$entry['href'];
                        }
                    }
                }
            });

            return $offered;
        };

        expect($menu(companyStaffScreens([B2BPermissions::COMPANY_VIEW])))->toBe(['companies/companies /admin/companies'])
            ->and($menu(companyStaffScreens([B2BPermissions::COMPANY_TYPE_UPDATE, B2BPermissions::DOCUMENT_TYPE_UPDATE])))
            ->toBe(['companies/company_types /admin/company-types', 'companies/document_types /admin/document-types'])
            ->and($menu(companyStaffScreens([B2BPermissions::COMPANY_REVIEW])))->toBe([]);
    });
});

describe('a Super Admin who decided (access.md amendments 54, 57)', function () {
    it('reads as "System administrator", with no name, to a reader who is not a Super Admin', function () {
        $customerId = B2BFixtures::verifiedCompanyAccount();
        [$company, $application] = B2BFixtures::sent($customerId);
        $superAdminId = Fx::staff(superAdmin: true, firstName: 'Hidden');
        $application->approve($superAdminId, null, CarbonImmutable::now());
        app(ApplicationRepository::class)->update($application);
        $company->approve($superAdminId, CarbonImmutable::now());
        app(CompanyRepository::class)->update($company);

        companyStaffScreens([B2BPermissions::COMPANY_VIEW], ['sa'])
            ->get("/admin/companies/{$company->id()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.0.decidedBy', 'System administrator')
                ->where('company.statusChangedBy', 'System administrator')
            )
            ->assertDontSee('Hidden');

        // Another Super Admin is told who it was.
        companyStaffScreensSignIn(Fx::staff(superAdmin: true))
            ->get("/admin/companies/{$company->id()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.0.decidedBy', fn (?string $name): bool => $name !== null && str_contains($name, 'Hidden'))
            );
    });
});
