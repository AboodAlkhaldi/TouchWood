<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\Popup;

/** Pop-ups (content.md §1.10, §5: `content.popups`). */
interface Popups
{
    public function find(string $id): ?Popup;

    public function lock(string $id): ?Popup;

    /**
     * The store's scheduled pop-ups, the ended included: what a new schedule may clash with.
     *
     * @return list<Popup>
     */
    public function scheduledIn(string $storeId): array;

    public function add(Popup $popup): void;

    public function update(Popup $popup): void;

    public function delete(string $id): void;
}
