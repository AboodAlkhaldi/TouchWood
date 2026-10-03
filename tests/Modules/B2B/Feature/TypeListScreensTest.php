<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\B2B\Application\B2BPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 7: the types page over HTTP (b2b.md §1.3, §4.6, amendment 21) — one store's company types
| and document types, for the store in the panel's header, and every change behind its buttons:
| refused without the job, another store's type answered as one that does not exist, the happy path,
| and a value the domain refuses said beside its field.
|
| The use cases have their own tests (B2BStaffTypesTest); these are about the screens. Every helper is
| named after this file's subject: a Pest file's functions are global.
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
 * Signed in through the panel's door; the panel opens in the person's first store.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function typeListScreens(array $permissions, array $stores = ['sa']): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores);
    $browser = new AdminBrowser('10.9.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function typeListScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

function typeListScreensNotice(string $storeCode = 'sa'): bool
{
    return (bool) DB::table('b2b.store_type_lists')->where('store_id', Fx::storeId($storeCode))->value('copied_not_reviewed');
}

function typeListScreensNotFound(): string
{
    return trans('b2b::errors.type_not_found.detail', [], 'en');
}

/**
 * A company whose first application waits, holding the store's second company type.
 */
function typeListScreensHolder(): string
{
    [$company] = B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());

    return $company->id();
}

describe('the types page', function () {
    it('shows the company types of the store in the header, with the notice and what the reader may do', function () {
        typeListScreensHolder();
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_UPDATE]);

        $browser->get('/admin/company-types')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('B2B/Admin/Types/Index')
                ->where('kind', 'company')
                ->where('storeName', 'Saudi Arabia')
                ->where('copiedNotReviewed', true)
                ->has('types', count(B2BFixtures::companyTypes()))
                ->where('types.1.holders', 1)
                ->where('types.0.holders', 0)
                ->where('types.0.required', null)
                ->where('actions.mayUpdate', true)
                ->where('actions.mayAdd', false)
                ->where('actions.mayDeactivate', false)
                ->where('actions.mayMarkReviewed', true)
                ->where('actions.mayReadDocumentTypes', false)
            );
    });

    it('shows the document types to a reader of that list, and refuses them the other list', function () {
        $browser = typeListScreens([B2BPermissions::DOCUMENT_TYPE_CREATE]);

        $browser->get('/admin/document-types')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kind', 'document')
                ->has('types', count(B2BFixtures::documentTypes()))
                ->where('types.0.required', true)
                ->where('types.0.holders', null)
                ->where('actions.mayAdd', true)
            );

        $browser->get('/admin/company-types')->assertForbidden();
    });

    it('shows the lists of the reader\'s own store, which is the one in the header', function () {
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_UPDATE], ['eg']);

        $browser->get('/admin/company-types')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('storeName', 'Egypt'));
    });

    it('refuses someone holding no job on the lists', function () {
        $browser = typeListScreens([B2BPermissions::COMPANY_VIEW, B2BPermissions::COMPANY_CORRECT_TYPE]);

        $browser->get('/admin/company-types')->assertForbidden();
        $browser->get('/admin/document-types')->assertForbidden();
    });
});

describe('adding, renaming and moving', function () {
    it('adds a company type to the header\'s store, and the notice goes', function () {
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_CREATE]);

        $added = $browser->post('/admin/company-types', ['name_ar' => 'جمعية تعاونية', 'name_en' => 'Cooperative Society', 'position' => '70']);

        expect(AdminBrowser::flashed($added, 'status'))->toBe('Company type added')
            ->and(DB::table('b2b.company_types')->where('store_id', Fx::storeId('sa'))->where('name_en', 'Cooperative Society')->value('position'))->toBe(70)
            ->and(DB::table('b2b.company_types')->where('store_id', Fx::storeId('eg'))->where('name_en', 'Cooperative Society')->exists())->toBeFalse()
            ->and(typeListScreensNotice())->toBeFalse();
    });

    it('adds an optional document type', function () {
        $browser = typeListScreens([B2BPermissions::DOCUMENT_TYPE_CREATE]);

        $browser->post('/admin/document-types', ['name_ar' => 'خطاب بنكي', 'name_en' => 'Bank letter', 'position' => '40', 'required' => false]);

        expect((bool) DB::table('b2b.document_types')->where('store_id', Fx::storeId('sa'))->where('name_en', 'Bank letter')->value('is_required'))->toBeFalse();
    });

    it('says an empty name, a position out of range or not a number beside its field, and a taken name at the top', function () {
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_CREATE]);
        $taken = B2BFixtures::companyTypes()[0]->name()->en;

        expect(typeListScreensErrors($browser->post('/admin/company-types', ['name_ar' => '', 'name_en' => 'Cooperative Society', 'position' => '70'])))->toHaveKey('name_ar')
            ->and(typeListScreensErrors($browser->post('/admin/company-types', ['name_ar' => 'جمعية', 'name_en' => 'Cooperative Society', 'position' => '10001'])))->toHaveKey('position')
            ->and(typeListScreensErrors($browser->post('/admin/company-types', ['name_ar' => 'جمعية', 'name_en' => 'Cooperative Society', 'position' => 'ten'])))->toHaveKey('position')
            ->and(AdminBrowser::formError($browser->post('/admin/company-types', ['name_ar' => 'جمعية', 'name_en' => strtoupper($taken), 'position' => '70'])))
            ->toBe(trans('b2b::errors.type_name_taken.detail', [], 'en'))
            ->and(DB::table('b2b.company_types')->where('store_id', Fx::storeId('sa'))->count())->toBe(count(B2BFixtures::companyTypes()))
            ->and(typeListScreensNotice())->toBeTrue();
    });

    it('refuses adding to someone without the job', function () {
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_UPDATE]);

        expect(AdminBrowser::formError($browser->post('/admin/company-types', ['name_ar' => 'جمعية', 'name_en' => 'Cooperative Society', 'position' => '70'])))->not->toBeNull()
            ->and(DB::table('b2b.company_types')->where('name_en', 'Cooperative Society')->exists())->toBeFalse();
    });

    it('renames and moves a type, and answers another store\'s type as one that does not exist', function () {
        $mine = B2BFixtures::companyTypes()[0]->id();
        $theirs = B2BFixtures::documentTypes('eg')[0]->id();
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_UPDATE, B2BPermissions::DOCUMENT_TYPE_UPDATE]);

        expect(AdminBrowser::flashed($browser->post("/admin/company-types/{$mine}/rename", ['name_ar' => 'منشأة', 'name_en' => 'Establishment']), 'status'))->toBe('Company type renamed')
            ->and(AdminBrowser::flashed($browser->post("/admin/company-types/{$mine}/move", ['position' => '900']), 'status'))->toBe('Position changed')
            ->and(DB::table('b2b.company_types')->where('id', $mine)->first(['name_en', 'position']))->toEqual((object) ['name_en' => 'Establishment', 'position' => 900])
            ->and(AdminBrowser::formError($browser->post("/admin/document-types/{$theirs}/rename", ['name_ar' => 'ورقة', 'name_en' => 'Paper'])))->toBe(typeListScreensNotFound());
    });

    it('refuses renaming and moving to someone without the job', function () {
        $mine = B2BFixtures::companyTypes()[0];
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_CREATE]);

        expect(AdminBrowser::formError($browser->post("/admin/company-types/{$mine->id()}/rename", ['name_ar' => 'منشأة', 'name_en' => 'Establishment'])))->not->toBeNull()
            ->and(AdminBrowser::formError($browser->post("/admin/company-types/{$mine->id()}/move", ['position' => '900'])))->not->toBeNull()
            ->and(DB::table('b2b.company_types')->where('id', $mine->id())->value('name_en'))->toBe($mine->name()->en);
    });

    it('makes a paper optional and required again', function () {
        $paper = B2BFixtures::documentTypes()[0]->id();
        $browser = typeListScreens([B2BPermissions::DOCUMENT_TYPE_UPDATE]);

        expect(AdminBrowser::flashed($browser->post("/admin/document-types/{$paper}/require", ['required' => false]), 'status'))->toBe('Document type made optional')
            ->and((bool) DB::table('b2b.document_types')->where('id', $paper)->value('is_required'))->toBeFalse()
            ->and(AdminBrowser::flashed($browser->post("/admin/document-types/{$paper}/require", ['required' => true]), 'status'))->toBe('Document type made required')
            ->and((bool) DB::table('b2b.document_types')->where('id', $paper)->value('is_required'))->toBeTrue();
    });
});

describe('deactivating, activating and moving companies', function () {
    it('deactivates a company type, greyed out, leaving its companies, and activates it again', function () {
        $holder = typeListScreensHolder();
        $type = (string) DB::table('b2b.companies')->where('id', $holder)->value('company_type_id');
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_DEACTIVATE]);

        $deactivated = $browser->post("/admin/company-types/{$type}/deactivate", ['shown' => 'GREYED', 'holders' => 'leave']);

        expect(AdminBrowser::flashed($deactivated, 'status'))->toBe('Company type deactivated')
            ->and(DB::table('b2b.company_types')->where('id', $type)->first(['is_active', 'inactive_display']))->toEqual((object) ['is_active' => false, 'inactive_display' => 'GREYED'])
            ->and(DB::table('b2b.companies')->where('id', $holder)->value('company_type_id'))->toBe($type)
            ->and(AdminBrowser::flashed($browser->post("/admin/company-types/{$type}/activate"), 'status'))->toBe('Company type activated')
            ->and((bool) DB::table('b2b.company_types')->where('id', $type)->value('is_active'))->toBeTrue();
    });

    it('moves the holders to another active type when deactivating, and says a missing replacement beside it', function () {
        $holder = typeListScreensHolder();
        $type = (string) DB::table('b2b.companies')->where('id', $holder)->value('company_type_id');
        $other = B2BFixtures::companyTypes()[0]->id();
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_DEACTIVATE]);

        expect(typeListScreensErrors($browser->post("/admin/company-types/{$type}/deactivate", ['shown' => 'HIDDEN', 'holders' => 'replace', 'replacement' => ''])))->toHaveKey('replacement')
            ->and((bool) DB::table('b2b.company_types')->where('id', $type)->value('is_active'))->toBeTrue();

        $browser->post("/admin/company-types/{$type}/deactivate", ['shown' => 'HIDDEN', 'holders' => 'replace', 'replacement' => $other]);

        expect(DB::table('b2b.companies')->where('id', $holder)->value('company_type_id'))->toBe($other)
            ->and((bool) DB::table('b2b.company_types')->where('id', $type)->value('is_active'))->toBeFalse();
    });

    it('moves the holders to a new type made in the same step, only for someone who may add types as well', function () {
        $holder = typeListScreensHolder();
        $type = (string) DB::table('b2b.companies')->where('id', $holder)->value('company_type_id');
        $new = ['shown' => 'HIDDEN', 'holders' => 'new', 'new_name_ar' => 'شركة قابضة', 'new_name_en' => 'Holding Company', 'new_position' => ''];

        expect(AdminBrowser::formError(typeListScreens([B2BPermissions::COMPANY_TYPE_DEACTIVATE])->post("/admin/company-types/{$type}/deactivate", $new)))->not->toBeNull()
            ->and((bool) DB::table('b2b.company_types')->where('id', $type)->value('is_active'))->toBeTrue();

        $both = typeListScreens([B2BPermissions::COMPANY_TYPE_DEACTIVATE, B2BPermissions::COMPANY_TYPE_CREATE]);
        expect(typeListScreensErrors($both->post("/admin/company-types/{$type}/deactivate", [...$new, 'new_name_en' => ''])))->toHaveKey('new_name_en');

        $both->post("/admin/company-types/{$type}/deactivate", $new);
        $made = DB::table('b2b.company_types')->where('name_en', 'Holding Company')->first(['id', 'position']);

        expect($made)->not->toBeNull()
            // The old type's position, since none was given (amendment 11(b)).
            ->and($made?->position)->toBe((int) DB::table('b2b.company_types')->where('id', $type)->value('position'))
            ->and(DB::table('b2b.companies')->where('id', $holder)->value('company_type_id'))->toBe($made?->id);
    });

    it('refuses a look that is neither hidden nor greyed out, by shape', function () {
        $paper = B2BFixtures::documentTypes()[0]->id();
        $browser = typeListScreens([B2BPermissions::DOCUMENT_TYPE_DEACTIVATE]);

        expect(typeListScreensErrors($browser->post("/admin/document-types/{$paper}/deactivate", ['shown' => 'GONE'])))->toHaveKey('shown')
            ->and(AdminBrowser::flashed($browser->post("/admin/document-types/{$paper}/deactivate", ['shown' => 'HIDDEN']), 'status'))->toBe('Document type deactivated')
            ->and(AdminBrowser::flashed($browser->post("/admin/document-types/{$paper}/activate"), 'status'))->toBe('Document type activated');
    });

    it('refuses deactivating to someone without the job, and another store\'s type as not found', function () {
        $mine = B2BFixtures::companyTypes()[0]->id();
        $theirs = B2BFixtures::companyTypes('eg')[0]->id();

        expect(AdminBrowser::formError(typeListScreens([B2BPermissions::COMPANY_TYPE_UPDATE])->post("/admin/company-types/{$mine}/deactivate", ['shown' => 'HIDDEN'])))->not->toBeNull()
            ->and(AdminBrowser::formError(typeListScreens([B2BPermissions::COMPANY_TYPE_DEACTIVATE])->post("/admin/company-types/{$theirs}/deactivate", ['shown' => 'HIDDEN'])))->toBe(typeListScreensNotFound())
            ->and((bool) DB::table('b2b.company_types')->where('id', $mine)->value('is_active'))->toBeTrue()
            ->and((bool) DB::table('b2b.company_types')->where('id', $theirs)->value('is_active'))->toBeTrue();
    });

    it('moves every company of one active type to another, its own job, and says a missing target beside it', function () {
        $holder = typeListScreensHolder();
        $type = (string) DB::table('b2b.companies')->where('id', $holder)->value('company_type_id');
        $other = B2BFixtures::companyTypes()[0]->id();

        expect(AdminBrowser::formError(typeListScreens([B2BPermissions::COMPANY_TYPE_UPDATE, B2BPermissions::COMPANY_TYPE_DEACTIVATE])->post("/admin/company-types/{$type}/transfer", ['target' => $other])))->not->toBeNull()
            ->and(DB::table('b2b.companies')->where('id', $holder)->value('company_type_id'))->toBe($type);

        $mover = typeListScreens([B2BPermissions::COMPANY_TRANSFER_TYPE]);
        expect(typeListScreensErrors($mover->post("/admin/company-types/{$type}/transfer", ['target' => ''])))->toHaveKey('target');

        $moved = $mover->post("/admin/company-types/{$type}/transfer", ['target' => $other]);
        expect(AdminBrowser::flashed($moved, 'status'))->toBe('Companies moved')
            ->and(DB::table('b2b.companies')->where('id', $holder)->value('company_type_id'))->toBe($other)
            ->and((bool) DB::table('b2b.company_types')->where('id', $type)->value('is_active'))->toBeTrue();
    });
});

describe('marking the lists reviewed', function () {
    it('clears the notice of the header\'s store for either list\'s update job, and no other store\'s', function () {
        $browser = typeListScreens([B2BPermissions::DOCUMENT_TYPE_UPDATE]);

        $reviewed = $browser->post('/admin/type-lists/reviewed');

        expect(AdminBrowser::flashed($reviewed, 'status'))->toBe('Lists marked reviewed')
            ->and(typeListScreensNotice('sa'))->toBeFalse()
            ->and(typeListScreensNotice('eg'))->toBeTrue();
    });

    it('refuses someone with neither list\'s update job', function () {
        $browser = typeListScreens([B2BPermissions::COMPANY_TYPE_CREATE, B2BPermissions::DOCUMENT_TYPE_DEACTIVATE]);

        expect(AdminBrowser::formError($browser->post('/admin/type-lists/reviewed')))->not->toBeNull()
            ->and(typeListScreensNotice('sa'))->toBeTrue();
    });
});
