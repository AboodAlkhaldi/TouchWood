<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Media;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Dto\MediaUseDto;

/**
 * Sales's uses of Platform's media (sales.md §1.8, §1.9, §5): a bank-transfer document an order holds, and
 * a photo a return holds. Every use blocks the delete — the order's record of a payment, and the return's
 * evidence, must be kept (handoff §5.5).
 */
final readonly class SalesFilesUsage implements MediaUsage
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function usesOf(string $mediaId): array
    {
        $mediaId = strtolower($mediaId);

        return array_values([
            ...array_map(
                static fn ($orderId): MediaUseDto => new MediaUseDto('sales.order', (string) $orderId, true),
                $this->db->table('sales.transfer_documents')->where('media_id', $mediaId)->distinct()->orderBy('order_id')->pluck('order_id')->all(),
            ),
            ...array_map(
                static fn ($returnId): MediaUseDto => new MediaUseDto('sales.return', (string) $returnId, true),
                $this->db->table('sales.return_photos')->where('media_id', $mediaId)->distinct()->orderBy('return_id')->pluck('return_id')->all(),
            ),
        ]);
    }

    public function detach(string $mediaId): void
    {
        // Every use blocks, so Platform never gets here; reaching it means that rule was broken.
        throw new LogicException("A file an order or a return holds is never detached from it ({$mediaId}).");
    }
}
