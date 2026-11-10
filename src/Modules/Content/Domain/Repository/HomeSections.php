<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\HomeLogo;
use Modules\Content\Domain\Model\HomeSection;

/** A store's home sections and their logos (content.md §1.4, §5: `content.home_sections`, `home_section_media`). */
interface HomeSections
{
    public function find(string $id): ?HomeSection;

    /** The section's row locked for the change (`FOR UPDATE`). */
    public function lock(string $id): ?HomeSection;

    /**
     * Every section of the store, in order, locked when asked: Add and Order change the store's list.
     *
     * @return list<HomeSection>
     */
    public function ofStore(string $storeId, bool $lock = false): array;

    public function add(HomeSection $section): void;

    public function update(HomeSection $section): void;

    public function delete(string $id): void;

    /**
     * Each section's place, as listed.
     *
     * @param  list<string>  $ids
     */
    public function reorder(array $ids): void;

    /**
     * @return list<HomeLogo> in order
     */
    public function logos(string $sectionId): array;

    /**
     * @param  list<HomeLogo>  $logos  in order
     */
    public function replaceLogos(string $sectionId, array $logos): void;
}
