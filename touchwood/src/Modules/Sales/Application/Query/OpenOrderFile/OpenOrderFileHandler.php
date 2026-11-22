<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\OpenOrderFile;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Application\Query\Staff\StaffReturnReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * **A private file of an order, opened** (sales.md §3 OpenOrderFile): a transfer document or a return photo, for
 * staff who view the orders of its store — an expiring link to it (Platform's media; the caller checks the
 * reader first, as Platform asks). Any other file, or one of a store the reader does not cover, is no file:
 * `OrderNotFound`.
 */
final readonly class OpenOrderFileHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private StaffReturnReads $reads,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound
     */
    public function handle(OpenOrderFile $query): OrderFile
    {
        $covered = $this->authorizer->storesWith(self::PERMISSION);

        if ($this->actors->current()->type !== ActorType::Staff || $covered === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $mediaId = strtolower(trim($query->mediaId));
        $storeId = Ulids::valid($mediaId) ? $this->reads->storeOfFile($mediaId) : null;

        if ($storeId === null || ($covered !== null && ! in_array($storeId, array_map(static fn (StoreId $store): string => $store->value, $covered), true))) {
            throw new OrderNotFound;
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store(StoreId::fromString($storeId)));
        $urls = $this->platform->mediaUrlsOf([$mediaId])[$mediaId] ?? null;

        if ($urls === null || $urls->original === null) {
            throw new OrderNotFound;
        }

        return new OrderFile($mediaId, $urls->original, $urls->expiresAt);
    }
}
