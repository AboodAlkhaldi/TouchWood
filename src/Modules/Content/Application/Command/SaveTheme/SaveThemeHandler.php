<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveTheme;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Application\ThemeRules;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Model\ThemePart;
use Modules\Content\Domain\Repository\Themes;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Writes a theme (content.md §1.8, W5b), a Super Admin's (`content.theme.manage`, reserved, checked in each of its
 * stores): name per language (Arabic to save), start and end read on each store's own clock (Q1 (a)), its stores, its
 * 11 parts - artwork and words only. A new one is a draft. **A scheduled one, live included, may be edited in full**
 * (W5b: it shows at the next page) and stays held to Schedule's rules, the clash among them, under each store's theme
 * lock; an ended one is history and stays. Refused over a newer version.
 */
final readonly class SaveThemeHandler
{
    public const string PERMISSION = ContentPermissions::THEME_MANAGE;

    public const int MAX_NAME = 100;

    public function __construct(
        private ContentAccess $access,
        private Themes $themes,
        private ThemeRules $rules,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @return string the theme's id
     *
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveTheme $command): string
    {
        $this->access->holds(self::PERMISSION);
        $found = $command->themeId === null ? null : $this->themes->find($command->themeId);

        if ($command->themeId !== null) {
            foreach ($found === null ? [null] : array_keys($found->stores) as $storeId) {
                $this->access->authorize(self::PERMISSION, $storeId, item: true);
            }
        }

        $name = Words::arabic('name', $command->nameAr, $command->nameEn, self::MAX_NAME);
        $starts = Moments::wall('starts_at', $command->startsAt);
        $ends = Moments::wall('ends_at', $command->endsAt);

        if ($ends <= $starts) {
            throw new InvalidContentInput('ends_at', InvalidContentInput::DATES);
        }

        $stores = $this->rules->stores($command->storeIds);
        $moments = ThemeRules::moments($starts, $ends, $stores);
        $parts = $this->rules->parts($command->parts);
        $staffId = $this->access->staffId();
        $byId = [];

        foreach ($stores as $store) {
            $byId[$store->id] = $store;
        }

        return $this->db->transaction(function () use ($command, $found, $name, $starts, $ends, $moments, $parts, $staffId, $byId): string {
            $now = Moments::now();
            $this->rules->lockStores([...array_keys($byId), ...array_keys($found === null ? [] : $found->stores)]);

            if ($command->themeId === null) {
                $id = strtolower((string) Str::ulid());
                $theme = new Theme($id, $this->rules->slug($name['en'], $id), $name['ar'], $name['en'], false, $starts, $ends, $moments, 1, $staffId, $now);
                $this->themes->add($theme);
                $this->themes->replaceParts($id, $parts);
                $this->platform->recordAudit(ContentAudit::entry('content.theme.created', 'content.theme', $id, null, ContentAudit::diff([], [...$theme->fields(), ...self::partFields($parts)])));
                $this->cache->invalidate();

                return $id;
            }

            $was = $this->themes->lock($command->themeId) ?? throw new ContentNotFound;
            $this->check->version($command->version, $was->version, $was->updatedBy, $was->updatedAt, reset($byId) ?: throw new ContentNotFound);

            // An ended theme is history (§1.1 rule 11).
            if ($was->hasEnded($now)) {
                throw new InvalidContentInput('state', InvalidContentInput::STATE);
            }

            $theme = new Theme($was->id, $was->slug, $name['ar'], $name['en'], $was->isScheduled, $starts, $ends, $moments, $was->version + 1, $staffId, $now);

            if ($was->isScheduled) {
                $this->rules->requireSchedulable($theme, $parts, $byId, $now);
            }

            $before = $this->themes->partsOf($was->id);
            $this->themes->update($theme);
            $this->themes->replaceParts($was->id, $parts);
            $this->platform->recordAudit(ContentAudit::entry('content.theme.changed', 'content.theme', $was->id, null, ContentAudit::diff([...$was->fields(), ...self::partFields($before)], [...$theme->fields(), ...self::partFields($parts)])));
            $this->cache->invalidate();

            return $was->id;
        }, 3);
    }

    /**
     * The parts' short fields for the audit: words by value, each image by its id.
     *
     * @param  list<ThemePart>  $parts
     * @return array<string, bool|int|string|null>
     */
    public static function partFields(array $parts): array
    {
        $fields = [];

        foreach ($parts as $part) {
            $key = 'part.'.strtolower($part->part->value);
            $fields += [
                "{$key}.text_ar" => $part->textAr,
                "{$key}.text_en" => $part->textEn,
                "{$key}.link" => $part->link,
                ...ContentAudit::flatten($part->extras, "{$key}.extras"),
                ...ContentAudit::flatten($part->images, "{$key}.images"),
            ];
        }

        return $fields;
    }
}
