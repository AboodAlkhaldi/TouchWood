<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\OpenStaffView;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\StoresForStaff\StoresForStaff;
use Modules\Access\Application\StaffView\StaffViews;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * The staff view's pass (spec §1.11): every active staff member may look at the shop of one of their
 * stores, as a visitor sees it, and order nothing.
 *
 * The store is the one chosen in View Store's menu (amendment 64), and it must be one of theirs as
 * StoresForStaff answers - an off one only for a Super Admin, who prepares it (amendment 58(a)) - so
 * a request naming another is refused. Audited, with the store: looking at a closed store is
 * something somebody may ask about later.
 */
final readonly class OpenStaffViewHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_VIEW_OPEN;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private GrantsReader $grants,
        private StoresForStaff $stores,
        private StaffUserRepository $staff,
        private StaffViews $views,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @return string the store's id, whose shop opens
     *
     * @throws InvalidAccessAttribute when the store is not one of theirs
     * @throws StaffNotFound
     */
    public function handle(OpenStaffView $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $storeId = $this->stores->byCode($command->storeCode)?->id;

        if ($storeId === null) {
            throw new InvalidAccessAttribute('store', 'not one of your stores');
        }

        $grants = $this->grants->forStaff($staffId) ?? throw new StaffNotFound($staffId);

        $this->db->transaction(function () use ($staffId, $storeId, $grants): void {
            $staff = $this->staff->find($staffId) ?? throw new StaffNotFound($staffId);
            $this->views->open($staffId, StoreId::fromString($storeId), $grants->sessionVersion);
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.staff_view_opened', $staff, ['store_id' => $storeId]));
        });

        return $storeId;
    }
}
