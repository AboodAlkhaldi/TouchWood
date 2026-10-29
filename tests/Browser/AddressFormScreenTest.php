<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The address form on Platform's places, in a real browser (frontend.md §3.6, F9 on Platform's places;
| §8.4): a customer picks the region, then the city, then the district, each list read as it is
| opened; changing the region clears the city; "Other" asks for the city typed; the form fits a phone.
|
| No RefreshDatabase, as the other browser files: every name here is this run's own. The places are
| Jordan's, a country no store and no other test uses, so the other address tests keep typing theirs:
| this suite keeps what it writes.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    Cache::flush();
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A region with a city that has a district, and a second region, named for this run.
 *
 * @return array{region: string, city: string, district: string, other: string}
 */
function addressScreenPlaces(int $number): array
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    try {
        DB::table('platform.address_countries')->insertOrIgnore(['country_code' => 'JO', 'retired' => false, 'created_at' => now(), 'updated_at' => now()]);
        $add = fn (string $kind, ?string $parent, string $name): string => app(AddPlaceHandler::class)->handle(new AddPlace('JO', $kind, $parent, "{$name} ع", $name));
        $region = $add('REGION', null, "Region {$number}");
        $city = $add('CITY', $region, "City {$number}");
        $add('DISTRICT', $city, "District {$number}");
        $add('REGION', null, "Second {$number}");
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    return ['region' => "Region {$number}", 'city' => "City {$number}", 'district' => "District {$number}", 'other' => "Second {$number}"];
}

it('picks the region, city and district, clears what a change leaves behind, and fits a phone', function () {
    $number = random_int(100000, 999999);
    $places = addressScreenPlaces($number);

    $page = visit('/sa/en/register');
    $page->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', strtolower((string) Str::ulid()).'@example.test')
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en');

    $page->navigate('/sa/en/account?tab=addresses');
    $page->click('[data-test="add-address-sa"]')
        ->type('#label-sa', 'Home')
        ->type('#recipient-sa', 'Noura Saleh')
        ->type('#phone-sa', '+966512345678');

    // The country: Jordan, which no store ships to, so it is offered here (Q3).
    $page->click('[data-test="sa-country"]')
        ->type('[cmdk-input]', 'Jordan')
        ->click('[data-test="sa-country-JO"]');

    // The city waits for the region (Geist's disabled-with-reason).
    $page->assertSee('Choose the region first');

    $choose = function (string $picker, string $name) use ($page): void {
        $page->click("[data-test=\"sa-{$picker}-pick\"]");
        expect(browserSoon($page, "document.querySelector('[cmdk-input]') !== null && document.querySelector('[data-test=\"sa-{$picker}-pick-option\"]') !== null"))->toBeTrue();
        $page->type('[cmdk-input]', $name);
        $page->click("[data-test=\"sa-{$picker}-pick-option\"]");
    };

    $choose('region', $places['region']);
    $choose('city', $places['city']);
    $choose('district', $places['district']);
    $page->assertSeeIn('[data-test="sa-district-pick"]', $places['district']);

    // A new region clears the city and the district under it.
    $choose('region', $places['other']);
    $page->assertSeeIn('[data-test="sa-city-pick"]', 'Choose')
        ->assertSee('Choose the city first');

    // "Other": the city typed; its district is typed too.
    $page->click('[data-test="sa-city-pick"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"sa-city-pick-other\"]') !== null"))->toBeTrue();
    $page->click('[data-test="sa-city-pick-other"]')
        ->type('#sa-city', 'Diriyah')
        ->type('#sa-district', 'Turaif')
        ->type('#sa-street', 'King Fahd Road')
        ->type('#sa-building', '7');

    $page->resize(375, 812);
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->resize(1280, 800);

    $page->click('[data-test="save-address-sa"]');
    expect(browserSoon($page, "document.body.innerText.includes('Diriyah')"))->toBeTrue();
    $page->assertSee($places['other'])->assertNoJavaScriptErrors();
});
