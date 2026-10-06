<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Query\CustomerReader;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Contracts\ShopperLine;
use Modules\Access\Public\Contracts\ShopperLines;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Dto\ShopperLineDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\ShopperLineTone;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| What other modules add to the shop's frame, as every shop page carries it (access.md amendment
| 50): the account's side list — its own tabs and the pages other modules add for this kind of
| account — and the lines under the header, for whoever is signed in.
*/

uses(RefreshDatabase::class);

final class ShopFrameTestCountingReader implements CustomerReader
{
    public int $reads = 0;

    public function __construct(private readonly CustomerReader $reader) {}

    public function customers(?array $homeStoreIds, ?string $search, ?string $status, ?string $accountType, int $page, int $perPage): array
    {
        return $this->reader->customers($homeStoreIds, $search, $status, $accountType, $page, $perPage);
    }

    public function customer(string $customerId): ?array
    {
        $this->reads++;

        return $this->reader->customer($customerId);
    }
}

final class ShopFrameTestLine implements ShopperLine
{
    public static int $asked = 0;

    public function lineFor(string $customerId, AccountType $accountType, bool $emailVerified): ?ShopperLineDto
    {
        self::$asked++;

        return $accountType === AccountType::Company
            ? new ShopperLineDto($emailVerified ? 'Continue your application' : 'Confirm first', 'storefront.account', ShopperLineTone::Warn)
            : null;
    }
}

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    ShopFrameTestLine::$asked = 0;

    app(CustomerAccountPages::class)->register(new CustomerAccountPageDto('access', 'test_company', 'storefront.sign-in', AccountType::Company));
    app(ShopperLines::class)->register(ShopFrameTestLine::class);
});

function shopFrameSignedIn(string $accountType): AdminBrowser
{
    $customerId = Fx::customer(accountType: $accountType);
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

it('gives a company account its tabs, the pages added for company accounts, and its lines', function () {
    shopFrameSignedIn('company')->get('/sa/en/account')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accountMenu.tabs', [
            ['key' => 'profile', 'label' => 'Details'],
            ['key' => 'security', 'label' => 'Password'],
            ['key' => 'phone', 'label' => 'Phone'],
            ['key' => 'addresses', 'label' => 'Addresses'],
            ['key' => 'close', 'label' => 'Account Closure'],
        ])
        // Among whatever the real modules add as well — B2B's company page, from step 6.
        ->where('accountMenu.pages', fn (Collection $pages): bool => shopFrameHolds($pages, ['key' => 'access.test_company', 'label' => 'access::account_pages.test_company', 'routeName' => 'storefront.sign-in']))
        ->where('shopperLines', fn (Collection $lines): bool => shopFrameHolds($lines, ['text' => 'Confirm first', 'routeName' => 'storefront.account', 'tone' => 'warn'])));
});

/**
 * Whether a shared list holds this entry, whatever else is in it.
 *
 * @param  Collection<array-key, mixed>  $list
 * @param  array<string, string>  $entry
 */
function shopFrameHolds(Collection $list, array $entry): bool
{
    return $list->contains(static fn (mixed $each): bool => $each === $entry);
}

it('gives an individual account its tabs and nothing a company account is given', function () {
    shopFrameSignedIn('individual')->get('/sa/en/account')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('accountMenu.tabs', 5)
        ->where('accountMenu.pages', [])
        ->where('shopperLines', []));
});

it('gives a visitor neither, and never asks a line about nobody', function () {
    (new AdminBrowser)->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accountMenu', null)
        ->where('shopperLines', []));

    expect(ShopFrameTestLine::$asked)->toBe(0);
});

it('reads the customer once for everything the frame shares', function () {
    $browser = shopFrameSignedIn('company');
    $counting = new ShopFrameTestCountingReader(app(CustomerReader::class));
    app()->instance(CustomerReader::class, $counting);

    $browser->get('/sa/en/account')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('shopper')
        ->has('accountMenu.tabs', 5)
        ->has('shopperLines', 1));

    // The shopper, the side list and the lines: three things from one read.
    expect($counting->reads)->toBe(1)
        ->and(ShopFrameTestLine::$asked)->toBe(1);
});
