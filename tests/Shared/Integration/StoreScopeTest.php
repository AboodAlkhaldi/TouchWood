<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Shared\Application\MissingStoreContext;
use Shared\Application\StoreContext;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\BelongsToStore;

uses(RefreshDatabase::class);

final class ScopedWidget extends Model
{
    use BelongsToStore;

    protected $table = 'scope_test_widgets';

    protected $guarded = [];

    public $timestamps = false;
}

final class InMemoryStoreContext implements StoreContext
{
    public ?StoreId $store = null;

    public function current(): StoreId
    {
        return $this->store ?? throw MissingStoreContext::create();
    }

    public function has(): bool
    {
        return $this->store !== null;
    }

    public function runIn(StoreId $store, callable $callback): mixed
    {
        [$previous, $this->store] = [$this->store, $store];

        try {
            return $callback();
        } finally {
            $this->store = $previous;
        }
    }
}

final readonly class StoreScopeFixture
{
    public function __construct(
        public InMemoryStoreContext $stores,
        public StoreId $storeA,
        public StoreId $storeB,
    ) {}
}

/**
 * A throwaway store-scoped table with two rows in store A and one in store B.
 * Created inside the test's transaction, so it disappears afterwards.
 */
function storeScopeFixture(): StoreScopeFixture
{
    Schema::create('scope_test_widgets', function (Blueprint $table) {
        $table->id();
        $table->char('store_id', 26);
        $table->string('name');
    });

    $fixture = new StoreScopeFixture(
        new InMemoryStoreContext,
        StoreId::fromString((string) Str::ulid()),
        StoreId::fromString((string) Str::ulid()),
    );

    app()->instance(StoreContext::class, $fixture->stores);

    DB::table('scope_test_widgets')->insert([
        ['store_id' => $fixture->storeA->value, 'name' => 'a-hinge'],
        ['store_id' => $fixture->storeA->value, 'name' => 'a-slide'],
        ['store_id' => $fixture->storeB->value, 'name' => 'b-handle'],
    ]);

    return $fixture;
}

it('refuses to query with no store context instead of returning every store', function () {
    storeScopeFixture();

    expect(fn () => ScopedWidget::query()->get())->toThrow(MissingStoreContext::class);
});

it('returns only the current store rows', function () {
    $fixture = storeScopeFixture();
    $fixture->stores->store = $fixture->storeA;

    expect(ScopedWidget::query()->orderBy('name')->pluck('name')->all())->toBe(['a-hinge', 'a-slide']);
});

it('cannot find another store row by its id', function () {
    $fixture = storeScopeFixture();
    $otherStoreRow = DB::table('scope_test_widgets')->where('name', 'b-handle')->value('id');
    $fixture->stores->store = $fixture->storeA;

    expect(ScopedWidget::query()->find($otherStoreRow))->toBeNull();
});

it('limits updates and deletes to the current store', function () {
    $fixture = storeScopeFixture();
    $fixture->stores->store = $fixture->storeA;

    ScopedWidget::query()->update(['name' => 'renamed']);
    ScopedWidget::query()->delete();

    expect(DB::table('scope_test_widgets')->pluck('name')->all())->toBe(['b-handle']);
});

it('stamps new rows with the current store', function () {
    $fixture = storeScopeFixture();
    $fixture->stores->store = $fixture->storeB;

    $widget = ScopedWidget::query()->create(['name' => 'b-light']);

    expect($widget->getAttribute('store_id'))->toBe($fixture->storeB->value);
});

it('refuses to create a row with no store context', function () {
    storeScopeFixture();

    expect(fn () => ScopedWidget::query()->create(['name' => 'orphan']))->toThrow(MissingStoreContext::class);
});

it('reads across stores only through the explicit opt-out', function () {
    storeScopeFixture();

    expect(ScopedWidget::query()->acrossStores()->count())->toBe(3);
});

it('runs work in another store and then restores the previous one', function () {
    $fixture = storeScopeFixture();
    $fixture->stores->store = $fixture->storeA;

    $names = $fixture->stores->runIn($fixture->storeB, fn () => ScopedWidget::query()->pluck('name')->all());

    expect($names)->toBe(['b-handle'])
        ->and($fixture->stores->current()->equals($fixture->storeA))->toBeTrue();
});
