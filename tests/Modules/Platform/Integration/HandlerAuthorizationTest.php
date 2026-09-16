<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrency;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrencyHandler;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

final class PermissionLog implements Authorizer
{
    /** @var list<array{string, string|null}> */
    public array $checks = [];

    public bool $deny = false;

    public function authorize(string $permission, ?StoreId $store = null): void
    {
        $this->checks[] = [$permission, $store?->value];

        if ($this->deny) {
            throw new Unauthorized($permission);
        }
    }
}

function permissionLog(): PermissionLog
{
    $log = new PermissionLog;
    app()->instance(Authorizer::class, $log);

    return $log;
}

beforeEach(fn () => seed(PlatformSeeder::class));

it('checks the right permission, against the right store, in every handler', function (Closure $run, string $permission, ?string $storeCode) {
    $log = permissionLog();

    $run();

    $expectedStore = $storeCode === null ? null : app(PlatformApi::class)->storeByCode($storeCode)?->id;
    expect($log->checks)->toBe([[$permission, $expectedStore]]);
})->with([
    'create a currency' => [fn () => app(CreateCurrencyHandler::class)->handle(new CreateCurrency('XTS', 2, 'عملة', 'Currency', 'ع', 'XTS', null)), 'platform.currency.create', null],
    'update a currency' => [fn () => app(UpdateCurrencyHandler::class)->handle(new UpdateCurrency('SAR', nameEn: 'Riyal')), 'platform.currency.update', null],
    'create a store' => [fn () => app(CreateStoreHandler::class)->handle(new CreateStore('xa', 'متجر', 'Store', 'XA', 'SAR', 1500, 'UTC', 9)), 'platform.store.create', null],
    // Per-store: an admin of one store must not be able to edit another.
    'update a store' => [fn () => app(UpdateStoreHandler::class)->handle(new UpdateStore('eg', taxRateBasisPoints: 1500)), 'platform.store.update', 'eg'],
]);

it('changes nothing and audits nothing when the permission is denied', function (Closure $run) {
    permissionLog()->deny = true;
    $auditsBefore = DB::table('platform.audit_entries')->count();
    $storesBefore = DB::table('platform.stores')->get()->toJson();

    expect($run)->toThrow(Unauthorized::class)
        ->and(DB::table('platform.audit_entries')->count())->toBe($auditsBefore)
        ->and(DB::table('platform.stores')->get()->toJson())->toBe($storesBefore);
})->with([
    'update a store' => [fn () => app(UpdateStoreHandler::class)->handle(new UpdateStore('sa', taxRateBasisPoints: 1))],
    'create a store' => [fn () => app(CreateStoreHandler::class)->handle(new CreateStore('xa', 'متجر', 'Store', 'XA', 'SAR', 1500, 'UTC', 9))],
]);
