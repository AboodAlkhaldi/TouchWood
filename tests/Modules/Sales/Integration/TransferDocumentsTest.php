<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocument;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocumentHandler;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\TooManyFiles;
use Modules\Sales\Infrastructure\Media\SalesFilesUsage;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A company's bank-transfer documents (sales.md §1.9; b2b.md amendment 12(c)): on its own order paid by
| bank transfer, while it holds less than is due — up to 3, PDF, JPEG or PNG by what the file is, private;
| kept while the order holds them.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
});

afterEach(fn () => File::deleteDirectory(Bx::uploads()));

/** A company's order placed to be paid by bank transfer (or staff contact); acting as the company. */
function transferOrder(string $way = 'BANK_TRANSFER', string $storeCode = 'sa'): string
{
    $b2b = Mockery::mock(B2BApi::class);
    $b2b->shouldReceive('isApproved')->andReturn(true);
    $bankAccount = Mockery::mock(PaymentsApi::class);
    $bankAccount->shouldReceive('bankAccount')->andReturn(new BankAccountDto('SA00 0000', 'Bank', 'TouchWood'));
    app()->instance(PaymentsApi::class, $bankAccount);
    app()->instance(B2BApi::class, $b2b);
    $ready = Cx::ready('company', $storeCode);

    return Cx::place(Cx::start($ready['address'], $way, storeCode: $storeCode)->id, $storeCode)->id;
}

function transferUpload(string $order, ?string $path = null): string
{
    return app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($order, $path ?? Bx::pdf(), 'transfer.pdf'));
}

it('keeps up to three documents on the order, private, in order, then refuses a fourth', function () {
    $order = transferOrder();

    $media = [transferUpload($order), transferUpload($order), transferUpload($order)];

    expect(DB::table('sales.transfer_documents')->where('order_id', $order)->orderBy('position')->pluck('media_id')->all())->toBe($media)
        ->and(DB::table('platform.media')->whereIn('id', $media)->pluck('visibility')->unique()->all())->toBe(['PRIVATE'])
        ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'TRANSFER_DOCUMENT_UPLOADED')->count())->toBe(3)
        ->and(fn () => transferUpload($order))->toThrow(TooManyFiles::class);
});

it('takes a document only while the order paid by bank transfer holds less than is due', function (string $way, Closure $state) {
    $order = transferOrder($way);
    $state($order);

    expect(fn () => transferUpload($order))->toThrow(TooManyFiles::class)
        ->and(DB::table('sales.transfer_documents')->count())->toBe(0);
})->with([
    'staff will contact' => ['STAFF_CONTACT', fn (string $order) => null],
    'paid' => ['BANK_TRANSFER', fn (string $order) => DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')])],
    'cancelled' => ['BANK_TRANSFER', fn (string $order) => DB::table('sales.orders')->where('id', $order)->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancel_reason' => 'CHANGED_MIND', 'due_minor' => 0])],
]);

it('takes a document while some is still owed', function () {
    $order = transferOrder();
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor - 1')]);

    expect(transferUpload($order))->not->toBe('');
});

it('refuses a file that is not a PDF, JPEG or PNG, whatever its name', function () {
    $order = transferOrder();
    File::ensureDirectoryExists(Bx::uploads());
    $path = Bx::uploads().'/'.uniqid('', true).'-not.pdf';
    file_put_contents($path, 'plain words, not a document');

    expect(Pr::thrown(fn () => transferUpload($order, $path), InvalidSalesAttribute::class)->attribute)->toBe('file')
        ->and(Pr::thrown(fn () => transferUpload($order, Bx::uploads().'/missing.pdf'), InvalidSalesAttribute::class)->attribute)->toBe('file');
});

it('answers another customer order as no order', function () {
    $order = transferOrder();
    Fx::actAsCustomer(Cx::customer());

    expect(fn () => transferUpload($order))->toThrow(OrderNotFound::class);
});

it('keeps a document while an order holds it', function () {
    $order = transferOrder();
    $media = transferUpload($order);

    $uses = app(SalesFilesUsage::class)->usesOf($media);

    expect(array_map(fn ($use): array => [$use->subjectType, $use->subjectId, $use->blocksDelete], $uses))->toBe([['sales.order', $order, true]])
        ->and(app(SalesFilesUsage::class)->usesOf(strtolower((string) Str::ulid())))->toBe([])
        ->and(fn () => DB::table('platform.media')->where('id', $media)->delete())->toThrow(QueryException::class);
});

describe('what the review asked for', function () {
    it('answers a bad file the same whatever the order', function () {
        $order = transferOrder();
        Fx::actAsCustomer(Cx::customer());
        File::ensureDirectoryExists(Bx::uploads());
        $path = Bx::uploads().'/'.uniqid('', true).'-words.pdf';
        file_put_contents($path, 'plain words');

        expect(Pr::thrown(fn () => transferUpload($order, $path), InvalidSalesAttribute::class)->attribute)->toBe('file')
            ->and(Pr::thrown(fn () => transferUpload(strtolower((string) Str::ulid()), $path), InvalidSalesAttribute::class)->attribute)->toBe('file');
    });

    it('reads what the order holds, paid less refunded', function () {
        $order = transferOrder();
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor'), 'refunded_minor' => 1]);

        expect(transferUpload($order))->not->toBe('');
    });

    it('takes a document on an order of a store switched off since', function () {
        $order = transferOrder(storeCode: 'eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        expect(transferUpload($order))->not->toBe('');
    });

    it('holds the table rules in the database too', function (Closure $write, string $refusal) {
        $order = transferOrder();
        $media = transferUpload($order);

        expect(fn () => $write($order, $media))->toThrow(QueryException::class, $refusal);
    })->with([
        'a fourth place' => [fn (string $order, string $media) => DB::table('sales.transfer_documents')->insert(['order_id' => $order, 'store_id' => DB::table('sales.orders')->where('id', $order)->value('store_id'), 'media_id' => $media, 'position' => 4, 'uploaded_at' => now()]), 'transfer_documents_position'],
        'another store' => [fn (string $order, string $media) => DB::table('sales.transfer_documents')->insert(['order_id' => $order, 'store_id' => Fx::storeId('eg'), 'media_id' => $media, 'position' => 2, 'uploaded_at' => now()]), 'transfer_documents_order'],
    ]);
});
