<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\UploadTransferDocument;

use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Sales\Application\Order\OwnOrderAccess;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\TooManyFiles;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A company's bank-transfer document on its own order (sales.md §1.9; b2b.md amendment 12(c)): **up to
 * 3 files**, PDF, JPEG or PNG — by what the file is, never its name — private, while the order is paid by
 * bank transfer and holds less than is due (a balance after an edit included); otherwise `TooManyFiles`,
 * "a document while nothing is owed" (§7). Staff open them from the order and record the payment. An off
 * store's placed orders too (answer 39). The order locked first; in its history.
 */
final readonly class UploadTransferDocumentHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_MANAGE_OWN;

    /** The most documents an order holds (my assumption, stated for the owner to reject). */
    public const int MOST = 3;

    /** What a document may be. */
    public const array TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private OwnOrderAccess $access,
        private Orders $orders,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return string the document's media id
     *
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|TooManyFiles
     */
    public function handle(UploadTransferDocument $command): string
    {
        [$orderId, $store, $customerId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $type = is_file($command->path) ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($command->path) : '';

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidSalesAttribute('file', 'a PDF, JPEG or PNG');
        }

        return $this->db->transaction(function () use ($orderId, $store, $customerId, $command): string {
            $order = $this->orders->lock($orderId);

            if ($order === null || $order->customerId !== $customerId) {
                throw new OrderNotFound;
            }

            $held = $this->orders->transferDocuments($order->id);

            if ($order->wayToPay !== WayToPay::BankTransfer->value || $order->held() >= $order->due || count($held) >= self::MOST) {
                throw new TooManyFiles;
            }

            $mediaId = $this->platform->uploadMediaFor(new ModuleUploadDto(
                'sales', self::PERMISSION, PermissionScope::store($store), MediaVisibility::Private, $command->path, $command->originalFilename,
            ));
            $now = CarbonImmutable::now();
            $this->orders->addTransferDocument($order->id, $order->storeId, $mediaId, count($held) + 1, $now);
            $this->orders->recordHistory($order->id, $order->storeId, 'TRANSFER_DOCUMENT_UPLOADED', null, null, $now, ['mediaId' => $mediaId]);

            return $mediaId;
        }, 3);
    }
}
