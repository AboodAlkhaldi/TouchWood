<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaign;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaignHandler;
use Modules\Content\Application\Command\SetRunPrices\SetRunPrices;
use Modules\Content\Application\Command\SetRunPrices\SetRunPricesHandler;
use Modules\Content\Application\ContentPermissions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentScreen as Screen;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| A schedule stopped part way, as a person meets it (content.md §1.7 #15; second review of #382): the refused store's
| own reason in the words, and the rows it names marked, on the form's answer and on a JSON one. Pressed on W4b's own
| Schedule Campaign (`POST /admin/content/campaigns/{campaign}/schedule`, PR 8), which answers as the panel's
| controllers do (`FormErrors::back`: `errors.form` and `rows.*`; the problem document for JSON). PR 6 asked it of a
| probe route until the screen existed.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour()->startOfSecond());
    Carbon::setTestNow(CarbonImmutable::now());
    Queue::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/**
 * A campaign in two stores: the first in store-id order prices its product within the ceiling, the second past it.
 *
 * @return array{campaign: string, variant: string, refused: string, browser: AdminBrowser}
 */
function scheduleRefusalCampaign(): array
{
    $staff = Fx::staffWith([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::PRICING_PRICE_EDIT], ['sa', 'ae']);
    // Signed in first, then the fixtures written as them: a request of a test that acts as someone is theirs.
    $browser = Screen::as($staff);
    Fx::actAsStaff($staff);
    $product = Px::ready(['60 cm']);
    $variant = $product['variants'][0];
    Rx::set('sa', [$variant => '100']);
    Rx::set('ae', [$variant => '100']);
    $typed = static fn (int $days): string => CarbonImmutable::now()->addDays($days)->setTimezone('Asia/Riyadh')->format('Y-m-d 00:00');
    $campaign = app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', 'Offers', $typed(2), $typed(10), [Fx::storeId('sa'), Fx::storeId('ae')]));
    $runs = DB::table('content.campaign_runs')->where('campaign_id', $campaign)->orderBy('store_id')->pluck('id')->all();

    foreach ($runs as $n => $run) {
        $version = (int) DB::table('content.campaigns')->where('id', $campaign)->value('version');
        // 10% off in the first store; in the second a fixed 10.00 on the size priced 100.00: past the ceiling of 30%,
        // which Pricing refuses naming the size.
        $target = $n === 0 ? ['kind' => 'PRODUCT', 'id' => $product['product'], 'percentBp' => 1000] : ['kind' => 'SIZE', 'id' => $variant, 'amountMinor' => 1000];
        app(SetRunPricesHandler::class)->handle(new SetRunPrices($run, $version, ['lines' => 'BOTH', 'targets' => [$target], 'leftOut' => []]));
    }

    return ['campaign' => $campaign, 'variant' => $variant, 'refused' => (string) DB::table('content.campaign_runs')->where('id', $runs[1])->value('store_id'), 'browser' => $browser];
}

/** @return TestResponse<Response> */
function scheduleRefusalPress(AdminBrowser $browser, string $campaign, bool $json = false): TestResponse
{
    $version = (int) DB::table('content.campaigns')->where('id', $campaign)->value('version');
    $page = "/admin/content/campaigns/{$campaign}";

    return $browser->post("{$page}/schedule", ['version' => $version], $json ? Screen::JSON : Screen::fromPage($page));
}

it('says the refused store\'s own reason and marks the rows it names on the form\'s answer', function () {
    ['campaign' => $campaign, 'variant' => $variant, 'browser' => $browser] = scheduleRefusalCampaign();
    // The panel's words in English for these checks; Arabic's are CampaignsTest's.
    $browser->post('/admin/preferences', ['preference' => 'locale', 'value' => 'en']);

    $response = scheduleRefusalPress($browser, $campaign);
    $errors = Screen::errors($response);

    expect($response->getStatusCode())->toBe(302)
        ->and($errors)->toHaveKeys(['form', "rows.{$variant}"])
        // Pricing's own sentence about the ceiling, inside Content's.
        ->and($errors['form'])->toContain('ceiling', 'Open the page again')
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->orderBy('store_id')->pluck('state')->all())->toBe(['SCHEDULED', 'DRAFT']);
});

it('says the same on a JSON answer, with the refusal\'s status, and lists every store scheduled now', function () {
    ['campaign' => $campaign, 'refused' => $refused, 'browser' => $browser] = scheduleRefusalCampaign();
    $browser->post('/admin/preferences', ['preference' => 'locale', 'value' => 'en']);
    scheduleRefusalPress($browser, $campaign);

    // Pressed again: nothing scheduled by this press, the store from before still named.
    $response = scheduleRefusalPress($browser, $campaign, json: true);
    $first = (string) DB::table('content.campaign_runs')->where('campaign_id', $campaign)->where('store_id', '!=', $refused)->value('store_id');
    $name = (string) DB::table('platform.stores')->where('id', $first)->value('name->en');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('type'))->toBe('content.campaign_partly_scheduled')
        ->and((string) $response->json('detail'))->toContain('ceiling')
        ->and((string) $response->json('detail'))->toContain($name, 'Open the page again');
});
