<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteSmartBar;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Repository\SmartBars;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Deletes a smart bar (content.md §1.9). A smart bar has no Publish step and no draft (§4.1 names none), so the
 * draft-only rule of §1.1 rule 11 has nothing to hold: it goes, audited by value (the build's reading).
 */
final readonly class DeleteSmartBarHandler
{
    public const string PERMISSION = ContentPermissions::BAR_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private SmartBars $bars,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(DeleteSmartBar $command): void
    {
        $this->access->holds(self::PERMISSION);
        $store = $this->access->authorize(self::PERMISSION, $this->bars->find($command->smartBarId)?->storeId, item: true);

        $this->db->transaction(function () use ($command, $store): void {
            $was = $this->bars->lock($command->smartBarId) ?? throw new ContentNotFound;
            $this->bars->delete($was->id);
            $this->platform->recordAudit(ContentAudit::entry('content.smart_bar.deleted', 'content.smart_bar', $was->id, $store->id, ContentAudit::diff($was->fields(), [])));
            $this->cache->invalidate();
        }, 3);
    }
}
