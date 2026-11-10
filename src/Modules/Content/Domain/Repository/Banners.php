<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\Banner;
use Modules\Content\Domain\Model\BannerPlacement;

/** Banners and their images (content.md §1.6, §5: `content.banners`, `banner_images`). */
interface Banners
{
    public function find(string $id): ?Banner;

    public function lock(string $id): ?Banner;

    /**
     * A placement's banners in the store, in order, locked when asked.
     *
     * @return list<Banner>
     */
    public function inPlacement(string $storeId, BannerPlacement $placement, bool $lock = false): array;

    public function add(Banner $banner): void;

    public function update(Banner $banner): void;

    public function delete(string $id): void;

    /**
     * Each banner's place, as listed.
     *
     * @param  list<string>  $ids
     */
    public function reorder(array $ids): void;
}
