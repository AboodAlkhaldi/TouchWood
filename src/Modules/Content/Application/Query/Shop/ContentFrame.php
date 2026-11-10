<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Shop;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\Moments;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * **What every shop page carries from Content** (content.md §1.5): the announcement bar, the footer and the pop-up,
 * for a store and a language - **one cached read** (`ContentCache`, `frame:{store}:{locale}`). The cache keeps every
 * bar and pop-up that is scheduled or live, each with its start and end, and **this filters them by the clock on each
 * request**, so a bar or a pop-up starts and ends on the second with no cache trouble. The theme's parts join with
 * themes (plan PR 6, PR 9), which also registers this as the frame's part.
 *
 * - The bar: shown while on and inside its dates; with the bar off, nothing.
 * - The pop-up: **one at a time** - the first live one by its start. Whether a page shows it at all (not at checkout,
 *   not in the staff view, once per visitor) is the shop's to decide (§1.10).
 */
final readonly class ContentFrame
{
    public function __construct(
        private ContentCache $cache,
        private ShopReads $reads,
    ) {}

    /**
     * @return array{bar: array{text: string, link: string|null}|null, footer: array<string, mixed>|null, popup: array<string, mixed>|null}
     */
    public function for(StoreDto $store, string $locale, ?DateTimeImmutable $now = null): array
    {
        $now ??= Moments::now();
        $kept = $this->cache->remember("frame:{$store->id}:{$locale}", fn (): array => self::inLanguage($this->reads->frame($store->id), $locale));

        $bar = $kept['bar'];
        $popup = null;

        foreach ($kept['popups'] as $candidate) {
            if (self::liveAt($candidate, $now)) {
                $popup = array_diff_key($candidate, ['starts' => true, 'ends' => true]);

                break;
            }
        }

        return [
            'bar' => $bar !== null && self::liveAt($bar, $now) ? ['text' => (string) $bar['text'], 'link' => $bar['link']] : null,
            'footer' => $kept['footer'],
            'popup' => $popup,
        ];
    }

    /**
     * @param  array{bar: array<string, mixed>|null, footer: array<string, mixed>|null, popups: list<array<string, mixed>>}  $rows
     * @return array{bar: array{text: string, link: string|null, starts: string|null, ends: string|null}|null, footer: array<string, mixed>|null, popups: list<array<string, mixed>>}
     */
    private static function inLanguage(array $rows, string $locale): array
    {
        $bar = $rows['bar'];

        return [
            'bar' => $bar === null ? null : ['text' => (string) $bar["text_{$locale}"], 'link' => $bar['link'] === null ? null : (string) $bar['link'], 'starts' => self::text($bar['starts']), 'ends' => self::text($bar['ends'])],
            'footer' => $rows['footer'] === null ? null : self::footer($rows['footer'], $locale),
            'popups' => array_map(static fn (array $popup): array => [
                'id' => $popup['id'],
                'title' => $popup["title_{$locale}"],
                'text' => $popup["text_{$locale}"],
                'button' => $popup["button_{$locale}"],
                'link' => $popup['link'],
                'delaySeconds' => $popup['delay_seconds'],
                'image' => $popup['image'],
                'starts' => $popup['starts'],
                'ends' => $popup['ends'],
            ], $rows['popups']),
        ];
    }

    /**
     * @param  array<string, mixed>  $footer
     * @return array<string, mixed>
     */
    private static function footer(array $footer, string $locale): array
    {
        $columns = is_array($footer['columns'] ?? null) ? $footer['columns'] : [];

        return [
            'about' => $footer["about_{$locale}"],
            'phone' => $footer['phone'],
            'email' => $footer['email'],
            'address' => $footer["address_{$locale}"],
            'copyright' => $footer["copyright_{$locale}"],
            'columns' => array_map(static fn (array $column): array => [
                'title' => $column['title'][$locale] ?? '',
                'links' => array_map(static fn (array $link): array => ['text' => $link['text'][$locale] ?? '', 'link' => $link['link'] ?? ''], is_array($column['links'] ?? null) ? $column['links'] : []),
            ], $columns),
        ];
    }

    /**
     * Whether something kept with its start and end shows at that moment.
     *
     * @param  array<string, mixed>  $item
     */
    public static function liveAt(array $item, DateTimeImmutable $now): bool
    {
        $starts = self::text($item['starts'] ?? null);
        $ends = self::text($item['ends'] ?? null);

        return ($starts === null || CarbonImmutable::parse($starts) <= $now) && ($ends === null || CarbonImmutable::parse($ends) > $now);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
