<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\FillHomeSection;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSectionHandler;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentImages;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\TranslationMarks;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\HomeLogo;
use Modules\Content\Domain\Model\HomeSection;
use Modules\Content\Domain\Model\HomeSectionType;
use Modules\Content\Domain\Repository\HomeSections;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Content\Domain\Service\HomeSectionSettings;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Saves what staff fill in a home section (content.md §1.4), held to its type's rules (`HomeSectionSettings`):
 * Arabic wherever anything is typed; **a section shown must keep its English and its minimum**, so English blanked
 * on it is refused (§1.1 rule 2), and its logos must be ready. Refused over a newer version (§1.1 rule 12). Only the
 * logos band holds files: a logo is a public image, its name the logo's description.
 */
final readonly class FillHomeSectionHandler
{
    public const string PERMISSION = ContentPermissions::HOMEPAGE_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private HomeSections $sections,
        private ContentImages $images,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
        private TranslationMarks $marks,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(FillHomeSection $command): void
    {
        $this->access->holds(self::PERMISSION);
        $found = $this->sections->find($command->sectionId);
        $store = $this->access->authorize(self::PERMISSION, $found?->storeId, item: true);
        $staffId = $this->access->staffId();

        $type = $found->type ?? throw new ContentNotFound;
        $settings = HomeSectionSettings::read($type, $command->settings);
        $logos = $type === HomeSectionType::ClientLogos ? $this->logos($command->logos) : [];

        $this->db->transaction(function () use ($store, $command, $staffId, $settings, $logos): void {
            $section = $this->sections->lock($command->sectionId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $section->version, $section->updatedBy, $section->updatedAt, $store);

            if ($section->isVisible) {
                HomeSectionSettings::requireShowable($section->type, $settings, $logos);
                $this->images->requireReady(ShowHomeSectionHandler::logoBoxes($logos));
            }

            $this->marks->saved(UnreadTranslations::HOME_SECTION, $section->id, $store->id, $command->translated, $command->read, $section->isVisible);
            $before = $this->sections->logos($section->id);
            $this->sections->update(new HomeSection($section->id, $section->storeId, $section->type, $section->position, $section->isVisible, $settings, $section->version + 1, $staffId, Moments::now()));

            if ($section->type === HomeSectionType::ClientLogos) {
                $this->sections->replaceLogos($section->id, $logos);
            }

            // Every box a short field, by value, from and to (§1.1 rule 13): `title.ar`, `facts.0.en`, `logo.1.name_ar`.
            $changes = ContentAudit::diff(
                [...ContentAudit::flatten($section->settings), ...self::logoFields($before)],
                [...ContentAudit::flatten($settings), ...self::logoFields($logos)],
            );

            $this->platform->recordAudit(ContentAudit::entry('content.home_section.filled', 'content.home_section', $section->id, $store->id, $changes));
            $this->cache->invalidate();
        }, 3);
    }

    /**
     * @param  list<HomeLogo>  $logos
     * @return array<string, string|null>
     */
    private static function logoFields(array $logos): array
    {
        $fields = [];

        foreach ($logos as $index => $logo) {
            $n = $index + 1;
            $fields = [...$fields, "logo.{$n}.media" => $logo->mediaId, "logo.{$n}.name_ar" => $logo->nameAr, "logo.{$n}.name_en" => $logo->nameEn, "logo.{$n}.link" => $logo->link];
        }

        return $fields;
    }

    /**
     * @param  list<array{mediaId: string, nameAr: string|null, nameEn: string|null, link: string|null}>  $sent
     * @return list<HomeLogo>
     *
     * @throws InvalidContentInput
     */
    private function logos(array $sent): array
    {
        if (count($sent) > HomeSectionSettings::MAX_LOGOS) {
            throw new InvalidContentInput('logos', InvalidContentInput::TOO_MANY, values: ['max' => HomeSectionSettings::MAX_LOGOS]);
        }

        $files = [];

        foreach ($sent as $index => $logo) {
            $files['logo#'.($index + 1)] = $logo['mediaId'];
        }

        $files = $this->images->files($files);

        if (count(array_unique(array_filter($files))) !== count($files) || in_array(null, $files, true)) {
            throw new InvalidContentInput('logos', in_array(null, $files, true) ? InvalidContentInput::IMAGE_FILE : InvalidContentInput::DUPLICATE);
        }

        return array_map(static function (array $logo, ?string $mediaId): HomeLogo {
            $name = Words::arabic('logo_name', $logo['nameAr'], $logo['nameEn'], 100);

            return new HomeLogo((string) $mediaId, $name['ar'], $name['en'], Words::link('logo_link', $logo['link']));
        }, $sent, array_values($files));
    }
}
