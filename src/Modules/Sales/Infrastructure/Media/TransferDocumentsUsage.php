<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Media;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Dto\MediaUseDto;

/**
 * Sales's uses of Platform's media (sales.md §1.9, §5): a bank-transfer document an order holds. Every
 * use blocks the delete — the order's record of a payment must be kept (handoff §5.5).
 */
final readonly class TransferDocumentsUsage implements MediaUsage
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function usesOf(string $mediaId): array
    {
        return array_values(array_map(
            static fn ($orderId): MediaUseDto => new MediaUseDto('sales.order', (string) $orderId, true),
            $this->db->table('sales.transfer_documents')->where('media_id', strtolower($mediaId))->distinct()->orderBy('order_id')->pluck('order_id')->all(),
        ));
    }

    public function detach(string $mediaId): void
    {
        // Every use blocks, so Platform never gets here; reaching it means that rule was broken.
        throw new LogicException("A transfer document an order holds is never detached from it ({$mediaId}).");
    }
}
