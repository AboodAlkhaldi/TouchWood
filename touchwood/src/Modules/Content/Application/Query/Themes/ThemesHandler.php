<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Themes;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Model\Theme;
use Modules\Content\Domain\Model\ThemePart;
use Modules\Content\Domain\Repository\Themes;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Content\Domain\ValueObject\Schedule;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * What W5 and W5b show (content.md §4.4), a Super Admin's (`content.theme.manage`, reserved): **the list** of themes,
 * newest first, 50 at a time - each with its stores, its dates, how many of its 11 parts are filled and its state, one
 * state when every store agrees and Mixed otherwise (§4.1, read from each store's moments and the clock) - and **a
 * theme's page**: the theme, its filled parts, the campaigns linked to it (shown read-only) and Translate's mark.
 */
final readonly class ThemesHandler
{
    public const string PERMISSION = ContentPermissions::THEME_MANAGE;

    public const string MIXED = 'MIXED';

    public function __construct(
        private ContentAccess $access,
        private Themes $themes,
        private ScreenReads $reads,
        private UnreadTranslations $marks,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws Unauthorized
     */
    public function list(?string $after = null): array
    {
        $this->access->holds(self::PERMISSION);
        $now = Moments::now();

        return array_map(static function (array $row) use ($now): array {
            /** @var list<array{storeId: string, startsAt: \DateTimeImmutable|null, endsAt: \DateTimeImmutable|null}> $stores */
            $stores = $row['stores'];
            $states = array_map(static fn (array $store): string => (new Schedule((bool) $row['isScheduled'], $store['startsAt'], $store['endsAt']))->state($now)->value, $stores);

            return [
                ...$row,
                'stores' => array_map(static fn (array $store, string $state): array => ['storeId' => $store['storeId'], 'state' => $state, 'startsAt' => $store['startsAt']?->format(DATE_ATOM), 'endsAt' => $store['endsAt']?->format(DATE_ATOM)], $stores, $states),
                'state' => self::overall($states, (bool) $row['isScheduled']),
            ];
        }, $this->reads->themes($after));
    }

    /**
     * @return array{theme: Theme, stores: array<string, StoreDto>, parts: list<ThemePart>, state: string, states: array<string, string>, campaigns: list<array{id: string, nameAr: string, nameEn: string|null}>, unread: bool}
     *
     * @throws ContentError|Unauthorized
     */
    public function theme(string $themeId): array
    {
        $this->access->holds(self::PERMISSION);
        $theme = $this->themes->find($themeId);
        $stores = [];

        foreach ($theme === null ? [null] : array_keys($theme->stores) as $storeId) {
            $store = $this->access->authorize(self::PERMISSION, $storeId, item: true);
            $stores[$store->id] = $store;
        }

        if ($theme === null) {
            throw new ContentNotFound;
        }

        $now = Moments::now();
        $states = [];

        foreach (array_keys($theme->stores) as $storeId) {
            $states[$storeId] = $theme->scheduleIn($storeId)?->state($now)->value ?? 'DRAFT';
        }

        return [
            'theme' => $theme,
            'stores' => $stores,
            'parts' => $this->themes->partsOf($theme->id),
            'state' => self::overall(array_values($states), $theme->isScheduled),
            'states' => $states,
            'campaigns' => $this->reads->campaignsOfTheme($theme->id),
            'unread' => $this->marks->has(UnreadTranslations::THEME, $theme->id),
        ];
    }

    /**
     * One state when every store agrees, Mixed otherwise (§4.1).
     *
     * @param  list<string>  $states
     */
    private static function overall(array $states, bool $scheduled): string
    {
        $unique = array_values(array_unique($states));

        return match (count($unique)) {
            0 => $scheduled ? 'SCHEDULED' : 'DRAFT',
            1 => $unique[0],
            default => self::MIXED,
        };
    }
}
