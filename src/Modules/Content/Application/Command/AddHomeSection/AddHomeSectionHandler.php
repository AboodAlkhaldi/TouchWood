<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\AddHomeSection;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\HomeSection;
use Modules\Content\Domain\Model\HomeSectionType;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\HomeSections;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Shared\Application\Unauthorized;

/**
 * Adds a section type to a store's home page (content.md §1.4: "each type at most once"; Add Section brings back one
 * removed), hidden and empty, after the others. Under the store's home lock, so two presses add it once.
 */
final readonly class AddHomeSectionHandler
{
    public const string PERMISSION = ContentPermissions::HOMEPAGE_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private HomeSections $sections,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
        private ContentLocks $locks,
    ) {}

    /**
     * @return string the section's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(AddHomeSection $command): string
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);
        $type = HomeSectionType::tryFrom($command->type) ?? throw new InvalidContentInput('type', InvalidContentInput::CHOICE);
        $staffId = $this->access->staffId();

        return $this->db->transaction(function () use ($store, $type, $staffId): string {
            // The store's lock, not its rows': a store whose sections were all removed has none to hold.
            $this->locks->lock($store->id, ContentLocks::HOME);
            $present = $this->sections->ofStore($store->id);

            foreach ($present as $section) {
                if ($section->type === $type) {
                    throw new InvalidContentInput('type', InvalidContentInput::DUPLICATE);
                }
            }

            $id = strtolower((string) Str::ulid());
            $this->sections->add(new HomeSection($id, $store->id, $type, count($present), false, [], 1, $staffId, Moments::now()));
            $this->platform->recordAudit(ContentAudit::entry('content.home_section.added', 'content.home_section', $id, $store->id, AuditChanges::none()->changed('type', null, $type->value)));
            $this->cache->invalidate();

            return $id;
        }, 3);
    }
}
