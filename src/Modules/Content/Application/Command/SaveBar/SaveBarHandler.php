<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveBar;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\Bar;
use Modules\Content\Domain\Repository\FrameItems;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Saves a store's announcement bar (content.md §1.5). **It can't be switched on without both languages** (§1.1
 * rule 2: there is no Publish step); off, it keeps whatever Arabic was typed. One line of 120 characters, a link a
 * shop page or https:// address (§1.1 rule 16), an end after its start. Refused over a newer version.
 */
final readonly class SaveBarHandler
{
    public const string PERMISSION = ContentPermissions::BAR_MANAGE;

    public const int MAX_TEXT = 120;

    public function __construct(
        private ContentAccess $access,
        private FrameItems $frame,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveBar $command): void
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);
        $staffId = $this->access->staffId();

        $text = $command->isOn
            ? Words::both('text', $command->textAr, $command->textEn, self::MAX_TEXT)
            : Words::pair('text', $command->textAr, $command->textEn, self::MAX_TEXT);
        $link = Words::link('link', $command->link);
        [$starts, $ends] = Moments::range($command->startsAt, $command->endsAt, $store);

        $this->db->transaction(function () use ($store, $command, $staffId, $text, $link, $starts, $ends): void {
            $bar = $this->frame->bar($store->id, lock: true);
            $this->check->version($command->version, $bar->version, $bar->updatedBy, $bar->updatedAt, $store);
            $saved = new Bar($store->id, $text['ar'], $text['en'], $link, $command->isOn, $starts, $ends, $bar->version + 1, $staffId, Moments::now());
            $this->frame->saveBar($saved);
            $this->platform->recordAudit(ContentAudit::entry('content.bar.saved', 'content.bar', $store->id, $store->id, ContentAudit::diff($bar->fields(), $saved->fields())));
            $this->cache->invalidate();
        }, 3);
    }
}
