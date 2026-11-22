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
use Modules\Content\Domain\Repository\UnreadTranslations;
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
        private UnreadTranslations $marks,
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
        // Show asks for the tick on a section whose translated English nobody has read (§1.3, P14): one read for all.
        $unread = $this->marks->marked(UnreadTranslations::HOME_SECTION, array_map(static fn (HomeSection $section): string => $section->id, $sections));

        return [
            'sections' => array_map(static fn (HomeSection $section): array => [
                'translationUnread' => in_array(strtolower($section->id), $unread, true),
                'id' => $section->id,
                'type' => $section->type->value,
                'position' => $section->position,
                'isVisible' => $section->isVisible,
                'version' => $section->version,
                // For the list's one-line summary (§4.4 W1): read with the sections, no query more.
                'settings' => $section->settings,
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
            'translationUnread' => $this->marks->has(UnreadTranslations::HOME_SECTION, $section->id),
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
