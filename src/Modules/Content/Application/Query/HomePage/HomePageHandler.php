<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\HomePage;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\HomeLogo;
use Modules\Content\Domain\Model\HomeSection;
use Modules\Content\Domain\Model\HomeSectionType;
use Modules\Content\Domain\Repository\FrameItems;
use Modules\Content\Domain\Repository\HomeSections;
use Shared\Application\Unauthorized;

/**
 * What W1 shows (content.md §4.4): the store's sections in order - type, shown or hidden, the version a form saves
 * at, and the types Add Section may still offer - one section's form with its logos, and the Footer tab's form. Under
 * `content.homepage.manage` in the store; another store's section is not found.
 */
final readonly class HomePageHandler
{
    public const string PERMISSION = ContentPermissions::HOMEPAGE_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private HomeSections $sections,
        private FrameItems $frame,
    ) {}

    /**
     * @return array{sections: list<array<string, mixed>>, addable: list<string>}
     *
     * @throws ContentError|Unauthorized
     */
    public function sections(?string $storeId): array
    {
        $store = $this->access->authorize(self::PERMISSION, $storeId);
        $sections = $this->sections->ofStore($store->id);
        $present = array_map(static fn (HomeSection $section): string => $section->type->value, $sections);

        return [
            'sections' => array_map(static fn (HomeSection $section): array => [
                'id' => $section->id,
                'type' => $section->type->value,
                'position' => $section->position,
                'isVisible' => $section->isVisible,
                'version' => $section->version,
            ], $sections),
            'addable' => array_values(array_diff(array_map(static fn (HomeSectionType $type): string => $type->value, HomeSectionType::cases()), $present)),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ContentError|Unauthorized
     */
    public function section(string $sectionId): array
    {
        $this->access->holds(self::PERMISSION);
        $section = $this->sections->find($sectionId);
        $this->access->authorize(self::PERMISSION, $section?->storeId, item: true);

        /** @var HomeSection $section */
        return [
            'id' => $section->id,
            'type' => $section->type->value,
            'isVisible' => $section->isVisible,
            'version' => $section->version,
            'settings' => $section->settings,
            'logos' => array_map(static fn (HomeLogo $logo): array => ['mediaId' => $logo->mediaId, 'nameAr' => $logo->nameAr, 'nameEn' => $logo->nameEn, 'link' => $logo->link], $this->sections->logos($section->id)),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ContentError|Unauthorized
     */
    public function footer(?string $storeId): array
    {
        $store = $this->access->authorize(self::PERMISSION, $storeId);
        $footer = $this->frame->footer($store->id);

        return [...$footer->fields(), 'columns' => $footer->columns, 'version' => $footer->version];
    }
}
