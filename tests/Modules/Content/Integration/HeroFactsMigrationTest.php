<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| flags-2026-11.md S1.7 (owner, 2026-11-18; F6): the hero's saved facts move into the store's promises, after the
| ones it has, up to 5, each a promise with the icon `award`; a store with no promises section gets one, hidden. The
| hero keeps its title only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function heroFactsRun(): void
{
    $migration = require base_path('src/Modules/Content/Infrastructure/Persistence/Migrations/2026_11_19_100000_move_hero_facts_into_promises.php');
    $migration->up();
}

function heroFactsSettings(string $type, string $code): mixed
{
    $row = DB::table('content.home_sections')->where('store_id', Fx::storeId($code))->where('type', $type)->first();

    return $row === null ? null : json_decode((string) $row->settings, true);
}

it('moves the facts after the promises, up to 5, a figure as the title and its caption as the line', function () {
    $promise = ['icon' => 'truck', 'title' => ['ar' => 'شحن', 'en' => 'Delivery'], 'line' => ['ar' => 'سريع', 'en' => 'Fast']];
    DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', 'PROMISES')->update(['settings' => json_encode(['promises' => [$promise, $promise, $promise]])]);
    DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', 'HERO')->update(['settings' => json_encode(['title' => ['ar' => 'عنوان', 'en' => 'Title'], 'facts' => [
        ['figure' => ['ar' => 'TALLSEN', 'en' => 'TALLSEN'], 'caption' => ['ar' => 'وكيل معتمد', 'en' => 'Authorised dealer']],
        ['ar' => 'جدة', 'en' => 'Jeddah'],
        ['figure' => ['ar' => '24', 'en' => '24'], 'caption' => ['ar' => 'ساعة', 'en' => 'hours']],
    ]], JSON_UNESCAPED_UNICODE)]);

    heroFactsRun();

    expect(heroFactsSettings('HERO', 'sa'))->toEqual(['title' => ['ar' => 'عنوان', 'en' => 'Title']])
        ->and(heroFactsSettings('PROMISES', 'sa')['promises'])->toEqual([
            $promise, $promise, $promise,
            ['icon' => 'award', 'title' => ['ar' => 'TALLSEN', 'en' => 'TALLSEN'], 'line' => ['ar' => 'وكيل معتمد', 'en' => 'Authorised dealer']],
            // An old fact, a caption alone: its caption is the title.
            ['icon' => 'award', 'title' => ['ar' => 'جدة', 'en' => 'Jeddah'], 'line' => ['ar' => null, 'en' => null]],
        ]);
});

it('gives a store with no promises section a hidden one holding its facts', function () {
    DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->where('type', 'PROMISES')->delete();
    DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->where('type', 'HERO')->update(['settings' => json_encode(['facts' => [['ar' => 'حقيقة', 'en' => 'Fact']]], JSON_UNESCAPED_UNICODE)]);

    heroFactsRun();

    $row = DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->where('type', 'PROMISES')->first() ?? throw new LogicException('No PROMISES section was made.');

    expect($row->is_visible)->toBeFalse()
        ->and(heroFactsSettings('PROMISES', 'ae')['promises'][0]['title'])->toBe(['ar' => 'حقيقة', 'en' => 'Fact'])
        ->and(heroFactsSettings('HERO', 'ae'))->toBe([]);
});
