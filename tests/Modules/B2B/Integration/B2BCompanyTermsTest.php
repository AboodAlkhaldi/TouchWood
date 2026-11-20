<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Domain\Exception\CompanyTermsChanged;
use Modules\B2B\Domain\Exception\CompanyTermsNotAccepted;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Content\Application\Policies\PolicyInput;
use Modules\Platform\Public\Contracts\ShopPolicies;
use Modules\Platform\Public\Enums\PolicyKind;
use Shared\Application\ActorContext;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;
use Tests\Modules\Content\Support\ContentFixtures;

use function Pest\Laravel\seed;

/*
| A company application and the store's Company Terms (flags-2026-11.md S3.6, Q2 - owner: yes): while the store has
| them published, Send needs the tick - refused without it whatever the page sent, nothing created - and the
| application keeps the policy and the version ticked; with none published, no tick is asked and none kept.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
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

/** The store's Company Terms published, as a Super Admin; then acting as this account again. */
function companyTermsPublished(string $customerId): string
{
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $policy = ContentFixtures::publishedPolicy('COMPANY_TERMS');
    Fx::actAsCustomer($customerId);

    return $policy;
}

/** A verified company account, acting, with its draft filled in completely. */
function companyTermsReady(): string
{
    $customerId = B2BFixtures::verifiedCompanyAccount();
    Fx::actAsCustomer($customerId);
    app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
    app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft([
        'name' => 'Al Noor Trading',
        'company_type_id' => B2BFixtures::companyTypes()[0]->id(),
        'cr_number' => '1010123456',
        'tax_number' => '300123456700003',
        'address_id' => B2BFixtures::savedAddress((string) app(ActorContext::class)->current()->id),
    ]));

    foreach (B2BFixtures::documentTypes() as $type) {
        if ($type->isActive()) {
            app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($type->id(), B2BFixtures::pdf(), 'paper-'.$type->id().'.pdf'));
        }
    }

    return $customerId;
}

/**
 * Sent as the form sends it: the tick, and the Company Terms it showed - the store's now unless told.
 *
 * @param  array{0: ?string, 1: ?int}|null  $shown
 */
function companyTermsSend(bool $ticked, ?array $shown = null): void
{
    $live = app(ShopPolicies::class)->published(StoreId::fromString(Fx::storeId('sa')), PolicyKind::CompanyTerms, 'en');
    [$id, $version] = $shown ?? [$live?->policyId, $live?->version];
    app(SubmitApplicationHandler::class)->handle(new SubmitApplication($ticked, $id, $version));
}

/** The Company Terms saved with new words, as a Super Admin: their next version; then this account again. */
function companyTermsReword(string $policy, string $customerId): void
{
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(
        $policy, 'COMPANY_TERMS', [Fx::storeId('sa')], (int) DB::table('content.policies')->where('id', $policy)->value('version'),
        'شروط الشركات', 'Company Terms', 'شروط-الشركات', 'company-terms', 'نص آخر', 'Other words',
    )));
    Fx::actAsCustomer($customerId);
}

/** @return array{state: string, policy: ?string, version: ?int} */
function companyTermsApplication(string $customerId): array
{
    $row = DB::table('b2b.applications')->where('customer_id', $customerId)->sole();

    return ['state' => (string) $row->state, 'policy' => $row->company_terms_policy_id, 'version' => $row->company_terms_version === null ? null : (int) $row->company_terms_version];
}

it('refuses Send without the tick while the store has Company Terms, sending and creating nothing', function () {
    $customerId = companyTermsReady();
    companyTermsPublished($customerId);

    expect(fn () => companyTermsSend(false))->toThrow(CompanyTermsNotAccepted::class)
        ->and(companyTermsApplication($customerId))->toBe(['state' => 'DRAFT', 'policy' => null, 'version' => null])
        ->and(DB::table('b2b.companies')->count())->toBe(0);
});

it('keeps on the application the Company Terms ticked and their version', function () {
    $customerId = companyTermsReady();
    $policy = companyTermsPublished($customerId);
    companyTermsReword($policy, $customerId);

    companyTermsSend(true);

    expect(companyTermsApplication($customerId))->toBe(['state' => 'SUBMITTED', 'policy' => $policy, 'version' => 2]);
});

it('refuses Send under Company Terms other than the form showed, ticked, and sends nothing (owner, 2026-11-20)', function (Closure $shown) {
    $customerId = companyTermsReady();
    $policy = companyTermsPublished($customerId);

    expect(fn () => companyTermsSend(true, $shown($policy, $customerId)))->toThrow(CompanyTermsChanged::class)
        ->and(companyTermsApplication($customerId))->toBe(['state' => 'DRAFT', 'policy' => null, 'version' => null])
        ->and(DB::table('b2b.companies')->count())->toBe(0);
})->with([
    'reworded since' => [function (string $policy, string $customerId): array {
        companyTermsReword($policy, $customerId);

        return [$policy, 1];
    }],
    'published since a form that showed none' => [fn (): array => [null, null]],
    'another policy' => [fn (): array => ['01katerms0000000000000000a', 1]],
    'another version' => [fn (string $policy): array => [$policy, 2]],
]);

it('refuses Send naming Company Terms the store no longer has', function () {
    $customerId = companyTermsReady();
    $policy = companyTermsPublished($customerId);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($policy, (int) DB::table('content.policies')->where('id', $policy)->value('version')));
    Fx::actAsCustomer($customerId);

    expect(fn () => companyTermsSend(true, [$policy, 1]))->toThrow(CompanyTermsChanged::class)
        ->and(companyTermsApplication($customerId)['state'])->toBe('DRAFT');
});

it('asks no tick and keeps none while the store has no Company Terms', function () {
    $customerId = companyTermsReady();

    companyTermsSend(false);

    expect(companyTermsApplication($customerId))->toBe(['state' => 'SUBMITTED', 'policy' => null, 'version' => null]);
});

it('asks another store\'s Company Terms of nobody here', function () {
    $customerId = companyTermsReady();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    ContentFixtures::publishedPolicy('COMPANY_TERMS', ['ae']);
    Fx::actAsCustomer($customerId);

    companyTermsSend(false);

    expect(companyTermsApplication($customerId)['state'])->toBe('SUBMITTED');
});
