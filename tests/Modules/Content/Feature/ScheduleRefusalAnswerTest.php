<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaign;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaignHandler;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaign;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaignHandler;
use Modules\Content\Application\Command\SetRunPrices\SetRunPrices;
use Modules\Content\Application\Command\SetRunPrices\SetRunPricesHandler;
use Modules\Content\Application\ContentPermissions;
use Shared\Domain\Error\DomainError;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| A schedule stopped part way, as a person meets it (content.md §1.7 #15; second review of #382): the refused store's
| own reason in the words, and the rows it names marked, on a form's answer and on a JSON one. W4b, which presses
| Schedule, is PR 8's, so a probe route answers the press as the panel's controllers do (`FormErrors::back`, the
| problem document for JSON).
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-11-10 10:00:00+00');
    Carbon::setTestNow(CarbonImmutable::now());
    Queue::fake();

    Route::middleware('web')->post('/_probe/content/schedule/{campaign}', function (Request $request, string $campaign) {
        // The panel's words in English for these checks; Arabic's are CampaignsTest's.
        app()->setLocale('en');

        try {
            app(ScheduleCampaignHandler::class)->handle(new ScheduleCampaign($campaign, (int) DB::table('content.campaigns')->where('id', $campaign)->value('version')));
        } catch (DomainError $refused) {
            return FormErrors::back($request, $refused);
        }

        return back();
    });
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/**
 * A campaign in two stores: the first in store-id order prices its product within the ceiling, the second past it.
 *
 * @return array{campaign: string, variant: string, refused: string}
 */
function scheduleRefusalCampaign(): array
{
    Cx::editor([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::PRICING_PRICE_EDIT], ['sa', 'ae']);
    $product = Px::ready(['60 cm']);
    $variant = $product['variants'][0];
    Rx::set('sa', [$variant => '100']);
    Rx::set('ae', [$variant => '100']);
    $campaign = app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', 'Offers', '2026-11-12 00:00', '2026-11-20 00:00', [Fx::storeId('sa'), Fx::storeId('ae')]));
    $runs = DB::table('content.campaign_runs')->where('campaign_id', $campaign)->orderBy('store_id')->pluck('id')->all();

    foreach ($runs as $n => $run) {
        $version = (int) DB::table('content.campaigns')->where('id', $campaign)->value('version');
        // 10% off in the first store; in the second a fixed 10.00 on the size priced 100.00: past the ceiling of 30%,
        // which Pricing refuses naming the size.
        $target = $n === 0 ? ['kind' => 'PRODUCT', 'id' => $product['product'], 'percentBp' => 1000] : ['kind' => 'SIZE', 'id' => $variant, 'amountMinor' => 1000];
        app(SetRunPricesHandler::class)->handle(new SetRunPrices($run, $version, ['lines' => 'BOTH', 'targets' => [$target], 'leftOut' => []]));
    }

    return ['campaign' => $campaign, 'variant' => $variant, 'refused' => (string) DB::table('content.campaign_runs')->where('id', $runs[1])->value('store_id')];
}

it('says the refused store\'s own reason and marks the rows it names on the form\'s answer', function () {
    ['campaign' => $campaign, 'variant' => $variant] = scheduleRefusalCampaign();

    $response = post("/_probe/content/schedule/{$campaign}");
    $response->assertSessionHasErrors(['form', "rows.{$variant}"]);
    $bag = app('session.store')->get('errors');
    $form = $bag instanceof ViewErrorBag ? (string) $bag->getBag('default')->first('form') : '';

    expect($response->getStatusCode())->toBe(302)
        // Pricing's own sentence about the ceiling, inside Content's.
        ->and($form)->toContain('ceiling', 'Open the page again')
        ->and(DB::table('content.campaign_runs')->where('campaign_id', $campaign)->orderBy('store_id')->pluck('state')->all())->toBe(['SCHEDULED', 'DRAFT']);
});

it('says the same on a JSON answer, with the refusal\'s status, and lists every store scheduled now', function () {
    ['campaign' => $campaign, 'refused' => $refused] = scheduleRefusalCampaign();
    post("/_probe/content/schedule/{$campaign}");

    // Pressed again: nothing scheduled by this press, the store from before still named.
    $response = postJson("/_probe/content/schedule/{$campaign}");
    $first = (string) DB::table('content.campaign_runs')->where('campaign_id', $campaign)->where('store_id', '!=', $refused)->value('store_id');
    $name = (string) DB::table('platform.stores')->where('id', $first)->value('name->en');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('type'))->toBe('content.campaign_partly_scheduled')
        ->and((string) $response->json('detail'))->toContain('ceiling')
        ->and((string) $response->json('detail'))->toContain($name, 'Open the page again');
});
