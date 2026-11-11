<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\RemoveHomeSection;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\HomeSections;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Shared\Application\Unauthorized;

/**
 * Removes a section from a store's home page (content.md §1.4), its words and logos with it; the others close up.
 * Its logos' files stay in the library.
 */
final readonly class RemoveHomeSectionHandler
{
    public const string PERMISSION = ContentPermissions::HOMEPAGE_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private HomeSections $sections,
        private ContentLocks $locks,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
        private TranslationMarks $marks,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(RemoveHomeSection $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->sections->find($command->sectionId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);

        $this->db->transaction(function () use ($store, $command): void {
            $this->locks->lock($store->id, ContentLocks::HOME);
            $section = $this->sections->lock($command->sectionId) ?? throw new ContentNotFound;
            $this->sections->delete($section->id);
            $this->marks->forget(UnreadTranslations::HOME_SECTION, $section->id);
            $rest = array_values(array_filter($this->sections->ofStore($store->id), static fn ($other): bool => $other->id !== $section->id));
            $this->sections->reorder(array_map(static fn ($other): string => $other->id, $rest));
            $this->platform->recordAudit(ContentAudit::entry('content.home_section.removed', 'content.home_section', $section->id, $store->id, AuditChanges::none()->changed('type', $section->type->value, null)));
            $this->cache->invalidate();
        }, 3);
    }
}
