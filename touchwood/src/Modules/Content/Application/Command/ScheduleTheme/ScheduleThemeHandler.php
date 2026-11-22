<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ScheduleTheme;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\ThemeRules;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Repository\Themes;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Schedules a draft theme (content.md §1.8): English beside every Arabic (a part with words on one side refuses,
 * naming it), every image's sizes ready, an end still to come in each store, and **no other theme of any of its stores
 * at a moment of it** - refused naming the other, the store and its dates (`ThemeClash`) - under each store's theme
 * lock, taken in store-id order first, so of two racing one wins. A draft never clashes. Translate's mark on its words
 * asks for the tick, which clears it (§1.3 #4).
 */
final readonly class ScheduleThemeHandler
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
        private TranslationMarks $marks,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(ScheduleTheme $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->themes->find($command->themeId);
        $stores = [];

        foreach ($found === null ? [null] : array_keys($found->stores) as $storeId) {
            $store = $this->access->authorize(self::PERMISSION, $storeId, item: true);
            $stores[$store->id] = $store;
        }

        $staffId = $this->access->staffId();

        $this->db->transaction(function () use ($command, $stores, $staffId): void {
            $now = Moments::now();
            $this->rules->lockStores(array_keys($stores));
            $was = $this->themes->lock($command->themeId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, reset($stores) ?: throw new ContentNotFound);

            if ($was->isScheduled) {
                throw new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            $theme = new Theme($was->id, $was->slug, $was->nameAr, $was->nameEn, true, $was->startsLocal, $was->endsLocal, $was->stores, $was->version + 1, $staffId, $now);
            $this->rules->requireSchedulable($theme, $this->themes->partsOf($was->id), $stores, $now);
            // Translate's English nobody has said they read asks for the tick; the tick clears it (§1.3 #4).
            $this->marks->showing(UnreadTranslations::THEME, $was->id, $command->read);
            $this->themes->update($theme);
            $this->platform->recordAudit(ContentAudit::entry('content.theme.scheduled', 'content.theme', $was->id, null, ContentAudit::diff($was->fields(), $theme->fields())));
            $this->cache->invalidate();
        }, 3);
    }
}
