<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Modules\Platform\Public\Contracts\PlatformApi;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

/**
 * @param  array<string, string>  $parameters
 */
function console(string $command, array $parameters): PendingCommand
{
    $pending = artisan($command, $parameters);

    if (! $pending instanceof PendingCommand) {
        throw new LogicException('Console output mocking is off, so the command output cannot be asserted.');
    }

    return $pending;
}

function createCurrencyFromConsole(): void
{
    console('platform:currency:create', [
        'code' => 'SAR',
        'exponent' => '2',
        '--name-ar' => 'ريال سعودي',
        '--name-en' => 'Saudi Riyal',
        '--abbreviation-ar' => 'ر.س',
        '--abbreviation-en' => 'SAR',
        '--sign' => "\u{20C1}",
    ])->assertSuccessful();
}

/**
 * @return array<string, string>
 */
function completeStoreOptions(): array
{
    return [
        'code' => 'sa',
        '--name-ar' => 'السعودية',
        '--name-en' => 'Saudi Arabia',
        '--country' => 'SA',
        '--currency' => 'SAR',
        '--tax-basis-points' => '1500',
        '--timezone' => 'Asia/Riyadh',
        '--position' => '1',
    ];
}

it('creates a currency with its sign', function () {
    createCurrencyFromConsole();

    expect(app(PlatformApi::class)->currency('SAR')?->sign)->toBe("\u{20C1}");
});

it('creates a complete store', function () {
    createCurrencyFromConsole();

    console('platform:store:create', completeStoreOptions())
        ->expectsOutput('Store sa created.')
        ->assertSuccessful();

    expect(app(PlatformApi::class)->storeByCode('sa')?->timezone)->toBe('Asia/Riyadh');
    assertDatabaseHas('platform.audit_entries', [
        'action' => 'platform.store.created',
        'store_id' => app(PlatformApi::class)->storeByCode('sa')?->id,
        'actor_type' => 'SYSTEM',
    ]);
});

it('refuses an incomplete store and creates nothing', function (string $missing, string $message) {
    createCurrencyFromConsole();
    $options = completeStoreOptions();
    unset($options[$missing]);

    console('platform:store:create', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(DB::table('platform.stores')->count())->toBe(0);
})->with([
    'no English name' => ['--name-en', 'both an Arabic and an English value'],
    'no timezone' => ['--timezone', 'not a valid IANA timezone'],
    'no tax rate' => ['--tax-basis-points', '--tax-basis-points is required'],
    'no country' => ['--country', 'Invalid store country'],
]);

it('refuses a store for a currency that was never created', function () {
    console('platform:store:create', completeStoreOptions())
        ->expectsOutputToContain('No currency has the code "SAR"')
        ->assertFailed();
});

it('refuses a non-numeric exponent', function () {
    console('platform:currency:create', ['code' => 'SAR', 'exponent' => 'two'])
        ->expectsOutput('The exponent must be a whole number.')
        ->assertFailed();
});
