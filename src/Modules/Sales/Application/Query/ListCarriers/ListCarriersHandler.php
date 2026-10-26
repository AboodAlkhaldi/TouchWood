<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListCarriers;

use Modules\Sales\Application\Query\Staff\StaffReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * **The carriers a store shipped with before** (sales.md §3.2; the Ship dialog's suggestions): each once,
 * the most lately shipped with first, at most 20 — for staff who ship that store's orders. The carrier is
 * still typed freely until stage 7 (§1.5).
 */
final readonly class ListCarriersHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public const int MOST = 20;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private StaffReads $reads,
    ) {}

    /**
     * @return list<string>
     *
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(ListCarriers $query): array
    {
        $id = strtolower(trim($query->storeId));
        $store = Ulids::valid($id) ? StoreId::fromString($id) : throw new InvalidSalesAttribute('store', 'a store');

        if ($this->actors->current()->type !== ActorType::Staff) {
            throw new Unauthorized(self::PERMISSION);
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));

        return $this->reads->carriers($store->value, self::MOST);
    }
}
