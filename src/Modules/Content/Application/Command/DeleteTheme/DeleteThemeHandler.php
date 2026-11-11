<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\DeleteTheme;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Repository\Themes;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Deletes a theme **only as a draft, and only unlinked** (content.md §1.1 rule 11, §5: `campaigns.theme_id` RESTRICT):
 * a scheduled, live or ended one is history, and a campaign's link is removed on the campaign first (`NotDeletable`).
 * Its parts and their images' rows go with it, the files released. Audited.
 */
final readonly class DeleteThemeHandler
{
    public const string PERMISSION = ContentPermissions::THEME_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Themes $themes,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private TranslationMarks $marks,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(DeleteTheme $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->themes->find($command->themeId);
        $stores = [];

        foreach ($found === null ? [null] : array_keys($found->stores) as $storeId) {
            $stores[] = $this->access->authorize(self::PERMISSION, $storeId, item: true);
        }

        $this->db->transaction(function () use ($command, $stores): void {
            $was = $this->themes->lock($command->themeId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, $stores[0]);

            if ($was->isScheduled || $this->themes->isLinked($was->id)) {
                throw new NotDeletable;
            }

            $this->themes->delete($was->id);
            // Its Translate mark goes with it (§1.3 #4).
            $this->marks->forget(UnreadTranslations::THEME, $was->id);
            $this->platform->recordAudit(ContentAudit::entry('content.theme.deleted', 'content.theme', $was->id, null, ContentAudit::diff($was->fields(), [])));
        }, 3);
    }
}
