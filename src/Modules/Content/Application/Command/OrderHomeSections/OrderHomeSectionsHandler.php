<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\OrderHomeSections;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Ordering;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\HomeSection;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\HomeSections;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Shared\Application\Unauthorized;

/**
 * Puts a store's home sections in the order dragged (content.md §1.4). The whole order the person sees is sent, and
 * must name every section of the store once (lesson 191); a short list saved as a whole, last save wins (§1.1
 * rule 12).
 */
final readonly class OrderHomeSectionsHandler
{
    public const string PERMISSION = ContentPermissions::HOMEPAGE_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private HomeSections $sections,
        private ContentLocks $locks,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(OrderHomeSections $command): void
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);

        $this->db->transaction(function () use ($store, $command): void {
            $this->locks->lock($store->id, ContentLocks::HOME);
            $present = $this->sections->ofStore($store->id);
            $ids = Ordering::of('sections', $command->sectionIds, array_map(static fn (HomeSection $section): string => $section->id, $present));
            $types = array_column(array_map(static fn (HomeSection $section): array => [$section->id, $section->type->value], $present), 1, 0);
            $this->sections->reorder($ids);
            $this->platform->recordAudit(ContentAudit::entry('content.home_sections.ordered', 'content.home_sections', $store->id, $store->id, AuditChanges::none()->changed(
                'order',
                implode(',', array_map(static fn (HomeSection $section): string => $section->type->value, $present)),
                implode(',', array_map(static fn (string $id): string => (string) $types[$id], $ids)),
            )));
            $this->cache->invalidate();
        });
    }
}
