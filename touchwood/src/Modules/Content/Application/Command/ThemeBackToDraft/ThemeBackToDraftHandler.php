<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ThemeBackToDraft;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\ThemeRules;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Repository\Themes;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Back to Draft (content.md §4.1): a scheduled theme **that has started in none of its stores** becomes a draft again;
 * a live one is ended instead (End Now). Under its stores' theme locks, refused over a newer version.
 */
final readonly class ThemeBackToDraftHandler
{
    public const string PERMISSION = ContentPermissions::THEME_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Themes $themes,
        private ThemeRules $rules,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(ThemeBackToDraft $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->themes->find($command->themeId);
        $stores = [];

        foreach ($found === null ? [null] : array_keys($found->stores) as $storeId) {
            $stores[] = $this->access->authorize(self::PERMISSION, $storeId, item: true);
        }

        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $stores, $staffId): void {
            $now = Moments::now();
            $this->rules->lockStores(array_map(static fn ($store): string => $store->id, $stores));
            $was = $this->themes->lock($command->themeId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, $stores[0]);

            if (! $was->isScheduled || $was->hasStarted($now)) {
                throw new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            $theme = new Theme($was->id, $was->slug, $was->nameAr, $was->nameEn, false, $was->startsLocal, $was->endsLocal, $was->stores, $was->version + 1, $staffId, $now);
            $this->themes->update($theme);
            $this->platform->recordAudit(ContentAudit::entry('content.theme.back_to_draft', 'content.theme', $was->id, null, ContentAudit::diff($was->fields(), $theme->fields())));
            $this->cache->invalidate();
        }, 3);
    }
}
