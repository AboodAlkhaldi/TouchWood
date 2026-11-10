<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveSeoDefaults;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentImages;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\SeoDefaults;
use Modules\Content\Domain\Repository\StoreSeo;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Saves a store's SEO defaults (content.md §1.14, W10). **Both languages, at once** (§1.1 rule 2: there is no Publish
 * step, and the words reach every page's head as they are saved). The title pattern holds `:title` (a page's own
 * title goes there; `:site` is the site's name), the share image is a public image of the library whose sizes are
 * made, and each robots line is a shop path without its store and language (`/brands/old`), which the robots file
 * writes for both languages. Last save wins (§1.1 rule 12 names no version here).
 */
final readonly class SaveSeoDefaultsHandler
{
    public const string PERMISSION = ContentPermissions::SEO_MANAGE;

    public const int MAX_SITE_NAME = 60;

    public const int MAX_PATTERN = 80;

    public const int MAX_DESCRIPTION = 160;

    public const int MAX_ROBOTS_LINES = 50;

    public const int MAX_ROBOTS_LINE = 200;

    public function __construct(
        private ContentAccess $access,
        private StoreSeo $seo,
        private ContentImages $images,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveSeoDefaults $command): void
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);
        $staffId = $this->access->staffId();

        $siteName = Words::both('site_name', $command->siteNameAr, $command->siteNameEn, self::MAX_SITE_NAME);
        $pattern = Words::both('title_pattern', $command->titlePatternAr, $command->titlePatternEn, self::MAX_PATTERN);
        $description = Words::both('description', $command->descriptionAr, $command->descriptionEn, self::MAX_DESCRIPTION);

        foreach (['ar', 'en'] as $locale) {
            if (! str_contains($pattern[$locale], ':title')) {
                throw new InvalidContentInput("title_pattern_{$locale}", InvalidContentInput::TITLE_PATTERN);
            }
        }

        $image = $this->images->files(['share_image' => $command->shareMediaId]);
        $this->images->requireReady($image);
        $robots = self::robotsLines($command->robotsLines);

        $this->db->transaction(function () use ($store, $staffId, $siteName, $pattern, $description, $image, $robots): void {
            $before = $this->seo->defaults($store->id, lock: true);
            $saved = new SeoDefaults($store->id, $siteName['ar'], $siteName['en'], $pattern['ar'], $pattern['en'], $description['ar'], $description['en'], $image['share_image'], $robots, $staffId, Moments::now());
            $this->seo->saveDefaults($saved);
            $this->platform->recordAudit(ContentAudit::entry('content.seo.saved', 'content.seo', $store->id, $store->id, ContentAudit::diff($before?->fields() ?? [], $saved->fields())));
        }, 3);
    }

    /**
     * The robots lines typed, one a line, blanks and repeats dropped: each a path of the shop without its store and
     * language - `/` and then no space, no control character, no backslash - of 200 characters at most, 50 at most.
     *
     * @return list<string>
     *
     * @throws InvalidContentInput
     */
    public static function robotsLines(?string $typed): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', (string) $typed) ?: [] as $number => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! str_starts_with($line, '/') || str_starts_with($line, '//') || mb_strlen($line) > self::MAX_ROBOTS_LINE
                || preg_match('//u', $line) !== 1 || preg_match('/[\s\p{Cc}\p{Cf}\\\\#]/u', $line) === 1) {
                throw new InvalidContentInput('robots_extra', InvalidContentInput::ROBOTS_PATH, $number + 1, ['max' => self::MAX_ROBOTS_LINE]);
            }

            $lines[$line] = true;
        }

        if (count($lines) > self::MAX_ROBOTS_LINES) {
            throw new InvalidContentInput('robots_extra', InvalidContentInput::TOO_MANY, values: ['max' => self::MAX_ROBOTS_LINES]);
        }

        return array_keys($lines);
    }
}
