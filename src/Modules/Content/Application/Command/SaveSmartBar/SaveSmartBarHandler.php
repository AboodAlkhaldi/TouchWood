<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveSmartBar;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\SmartBar;
use Modules\Content\Domain\Model\SmartBarScope;
use Modules\Content\Domain\Repository\SmartBars;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Writes a smart bar (content.md §1.9). **Both languages on save** - there is no Publish step (§1.1 rule 2) - 100
 * characters each; a link a shop page or an https:// address; a category bar names a category of the catalog, with
 * optional dates. **A campaign bar waits for campaign runs** (plan PR 6): until they exist there is no run to name,
 * so that choice is refused. A short row saved as it is: last save wins (§1.1 rule 12).
 */
final readonly class SaveSmartBarHandler
{
    public const string PERMISSION = ContentPermissions::BAR_MANAGE;

    public const int MAX_MESSAGE = 100;

    public function __construct(
        private ContentAccess $access,
        private SmartBars $bars,
        private CatalogApi $catalog,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @return string the smart bar's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveSmartBar $command): string
    {
        if ($command->smartBarId === null) {
            $store = $this->access->authorize(self::PERMISSION, $command->storeId);
        } else {
            $this->access->holds(self::PERMISSION);
            $store = $this->access->authorize(self::PERMISSION, $this->bars->find($command->smartBarId)?->storeId, item: true);
        }

        $scope = SmartBarScope::tryFrom($command->scope);

        // PR 6 brings campaign runs; a campaign bar is refused until a run can be named.
        if ($scope !== SmartBarScope::Category) {
            throw new InvalidContentInput('scope', InvalidContentInput::CHOICE);
        }

        $category = strtolower(trim((string) $command->categoryId));

        if ($category === '' || $this->catalog->category($category) === null) {
            throw new InvalidContentInput('category', InvalidContentInput::CATEGORY);
        }

        $message = Words::both('message', $command->messageAr, $command->messageEn, self::MAX_MESSAGE);
        $link = Words::link('link', $command->link);
        [$starts, $ends] = Moments::range($command->startsAt, $command->endsAt, $store);
        $staffId = $this->access->staffId();

        return $this->db->transaction(function () use ($command, $store, $scope, $category, $message, $link, $starts, $ends, $staffId): string {
            $was = $command->smartBarId === null ? null : ($this->bars->lock($command->smartBarId) ?? throw new ContentNotFound);
            $bar = new SmartBar($was->id ?? strtolower((string) Str::ulid()), $store->id, $scope, $category, null, $message['ar'], $message['en'], $link, $starts, $ends);

            if ($was === null) {
                $this->bars->add($bar, $staffId);
                $this->platform->recordAudit(ContentAudit::entry('content.smart_bar.added', 'content.smart_bar', $bar->id, $store->id, ContentAudit::diff([], $bar->fields())));
            } else {
                $this->bars->update($bar, $staffId);
                $this->platform->recordAudit(ContentAudit::entry('content.smart_bar.changed', 'content.smart_bar', $bar->id, $store->id, ContentAudit::diff($was->fields(), $bar->fields())));
            }

            $this->cache->invalidate();

            return $bar->id;
        }, 3);
    }
}
