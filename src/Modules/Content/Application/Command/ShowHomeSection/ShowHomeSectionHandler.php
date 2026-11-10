<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ShowHomeSection;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentImages;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Model\HomeLogo;
use Modules\Content\Domain\Model\HomeSection;
use Modules\Content\Domain\Repository\HomeSections;
use Modules\Content\Domain\Service\HomeSectionSettings;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Shared\Application\Unauthorized;

/**
 * Shows or hides a home section (content.md §1.4). **Show is refused without English beside every Arabic** - "Add
 * the English first." (`MissingEnglish`, W1) - without the type's minimum, and while a logo it would show is not
 * ready (§1.1 rule 10). Hiding is always allowed.
 */
final readonly class ShowHomeSectionHandler
{
    public const string PERMISSION = ContentPermissions::HOMEPAGE_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private HomeSections $sections,
        private ContentImages $images,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(ShowHomeSection $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->sections->find($command->sectionId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($store, $command, $staffId): void {
            $section = $this->sections->lock($command->sectionId) ?? throw new ContentNotFound;

            if ($section->isVisible === $command->visible) {
                return;
            }

            if ($command->visible) {
                $logos = $this->sections->logos($section->id);
                HomeSectionSettings::requireShowable($section->type, $section->settings, $logos);
                $this->images->requireReady(self::logoBoxes($logos));
            }

            $this->sections->update(new HomeSection($section->id, $section->storeId, $section->type, $section->position, $command->visible, $section->settings, $section->version, $staffId, Moments::now()));
            $this->platform->recordAudit(ContentAudit::entry(
                $command->visible ? 'content.home_section.shown' : 'content.home_section.hidden',
                'content.home_section',
                $section->id,
                $store->id,
                AuditChanges::none()->changed('is_visible', $section->isVisible, $command->visible),
            ));
            $this->cache->invalidate();
        });
    }

    /**
     * @param  list<HomeLogo>  $logos
     * @return array<string, string>
     */
    public static function logoBoxes(array $logos): array
    {
        $boxes = [];

        foreach ($logos as $index => $logo) {
            $boxes['logo#'.($index + 1)] = $logo->mediaId;
        }

        return $boxes;
    }
}
