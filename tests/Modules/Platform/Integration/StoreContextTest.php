<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Modules\Platform\Infrastructure\LaravelStoreContext;
use Shared\Application\MissingStoreContext;
use Shared\Application\StoreContext;
use Shared\Domain\ValueObject\StoreId;

uses(RefreshDatabase::class);

final class RecordStoreContextJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(StoreContext $stores): void
    {
        Cache::put('test:store-seen-by-job', $stores->has() ? $stores->current()->value : 'none');
    }
}

function newStoreId(): StoreId
{
    return StoreId::fromString((string) Str::ulid());
}

it('is the store context every module receives', function () {
    expect(app(StoreContext::class))->toBe(app(LaravelStoreContext::class));
});

it('throws when no store has been entered', function () {
    expect(app(StoreContext::class)->has())->toBeFalse()
        ->and(fn () => app(StoreContext::class)->current())->toThrow(MissingStoreContext::class);
});

it('holds the store that was entered', function () {
    $store = newStoreId();
    app(LaravelStoreContext::class)->enter($store);

    expect(app(StoreContext::class)->current()->equals($store))->toBeTrue();
});

it('runs work in another store and restores what was there before', function () {
    $outer = newStoreId();
    $inner = newStoreId();
    $context = app(LaravelStoreContext::class);

    $seen = $context->runIn($inner, fn (): string => $context->current()->value);
    expect($seen)->toBe($inner->value)->and($context->has())->toBeFalse();

    $context->enter($outer);
    $context->runIn($inner, fn () => null);
    expect($context->current()->equals($outer))->toBeTrue();
});

it('carries the store into a queued job run by another worker', function () {
    config(['queue.default' => 'database']);
    $store = newStoreId();
    app(LaravelStoreContext::class)->enter($store);

    RecordStoreContextJob::dispatch();

    // The worker is another process in production: nothing from this request survives.
    Context::flush();
    expect(app(StoreContext::class)->has())->toBeFalse();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    expect(Cache::get('test:store-seen-by-job'))->toBe($store->value);
});
