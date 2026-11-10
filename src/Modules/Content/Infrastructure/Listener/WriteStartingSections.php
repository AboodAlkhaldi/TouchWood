<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Listener;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Model\HomeSection;
use Modules\Content\Domain\Model\HomeSectionType;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\HomeSections;
use Modules\Platform\Public\Events\StoreCreated;

/**
 * **A new store starts with all eight sections, hidden, in the starting order** (content.md §1.4): nothing
 * unfinished goes out, and Add Section has nothing to bring back. A store that has sections already keeps them, so
 * a repeated event writes nothing twice. Stores that existed before Content's tables have theirs from the migration.
 */
final readonly class WriteStartingSections
{
    public function __construct(
        private HomeSections $sections,
        private ContentLocks $locks,
        private ConnectionInterface $db,
    ) {}

    public function handle(StoreCreated $event): void
    {
        $storeId = strtolower($event->storeId);

        $this->db->transaction(function () use ($storeId): void {
            $this->locks->lock($storeId, ContentLocks::HOME);

            if ($this->sections->ofStore($storeId) !== []) {
                return;
            }

            foreach (HomeSectionType::cases() as $type) {
                $this->sections->add(new HomeSection(strtolower((string) Str::ulid()), $storeId, $type, $type->startingPosition(), false, [], 1, null, Moments::now()));
            }
        });
    }
}
