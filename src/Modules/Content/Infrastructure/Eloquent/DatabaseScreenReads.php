<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\ValueObject\Schedule;
use stdClass;

/** `ScreenReads` in SQL (content.md §4.4): each list one query, its counts one more. */
final readonly class DatabaseScreenReads implements ScreenReads
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function banners(string $storeId, BannerPlacement $placement): array
    {
        $storeId = strtolower($storeId);

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            'SELECT b.id, b.name, b.category_id, b.is_scheduled, b.starts_at, b.ends_at, b.position, b.version,
                    (SELECT media_id FROM content.banner_images WHERE banner_id = b.id ORDER BY kind, locale LIMIT 1) AS thumbnail
             FROM content.banners b WHERE b.store_id = ? AND b.placement = ? ORDER BY b.position, b.id LIMIT 500',
            [$storeId, $placement->value],
        );
        $counts = array_fill_keys(array_map(static fn (BannerPlacement $case): string => $case->value, BannerPlacement::cases()), 0);

        foreach ($this->db->select('SELECT placement, count(*) AS n FROM content.banners WHERE store_id = ? GROUP BY placement', [$storeId]) as $count) {
            /** @var stdClass $count */
            $counts[(string) $count->placement] = (int) $count->n;
        }

        $now = Moments::now();

        return [
            'rows' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'categoryId' => Rows::text($row->category_id),
                'state' => (new Schedule((bool) $row->is_scheduled, Rows::moment($row->starts_at), Rows::moment($row->ends_at)))->state($now)->value,
                'startsAt' => Rows::moment($row->starts_at)?->format(DATE_ATOM),
                'endsAt' => Rows::moment($row->ends_at)?->format(DATE_ATOM),
                'position' => (int) $row->position,
                'version' => (int) $row->version,
                'thumbnail' => Rows::text($row->thumbnail),
            ], $rows),
            'counts' => $counts,
        ];
    }

    public function popups(string $storeId, ?string $after): array
    {
        $bindings = [strtolower($storeId)];
        $cursor = '';

        if ($after !== null) {
            $at = $this->db->selectOne('SELECT created_at, id FROM content.popups WHERE id = ? AND store_id = ?', [strtolower($after), strtolower($storeId)]);

            if (! $at instanceof stdClass) {
                return [];
            }

            $cursor = ' AND (created_at, id) < (?::timestamptz, ?)';
            $bindings = [...$bindings, (string) $at->created_at, (string) $at->id];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->select("SELECT id, name, is_scheduled, starts_at, ends_at, version FROM content.popups WHERE store_id = ?{$cursor} ORDER BY created_at DESC, id DESC LIMIT ?", [...$bindings, self::PAGE]);
        $now = Moments::now();

        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'state' => (new Schedule((bool) $row->is_scheduled, Rows::moment($row->starts_at), Rows::moment($row->ends_at)))->state($now)->value,
            'startsAt' => Rows::moment($row->starts_at)?->format(DATE_ATOM),
            'endsAt' => Rows::moment($row->ends_at)?->format(DATE_ATOM),
            'version' => (int) $row->version,
        ], $rows);
    }

    public function smartBars(string $storeId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT id, scope, category_id, run_id, message_ar, message_en, link, starts_at, ends_at FROM content.smart_bars WHERE store_id = ? ORDER BY created_at DESC, id DESC LIMIT 500', [strtolower($storeId)]);
        $now = Moments::now();

        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'scope' => (string) $row->scope,
            'categoryId' => Rows::text($row->category_id),
            'runId' => Rows::text($row->run_id),
            'messageAr' => (string) $row->message_ar,
            'messageEn' => (string) $row->message_en,
            'link' => Rows::text($row->link),
            // No draft: a smart bar is live inside its dates (§1.9), ended after its end.
            'state' => (new Schedule(true, Rows::moment($row->starts_at), Rows::moment($row->ends_at)))->state($now)->value,
            'startsAt' => Rows::moment($row->starts_at)?->format(DATE_ATOM),
            'endsAt' => Rows::moment($row->ends_at)?->format(DATE_ATOM),
        ], $rows);
    }

    public function documents(DocumentKind $kind, string $storeId, ?Publication $tab, ?string $after, ?string $categoryId): array
    {
        $tables = DocumentTables::of($kind);
        $storeId = strtolower($storeId);
        $where = 'd.store_id = ?';
        $bindings = [$storeId];

        if ($tab !== null) {
            $where .= ' AND d.status = ?';
            $bindings[] = $tab->value;
        }

        if ($kind === DocumentKind::Post && $categoryId !== null) {
            $where .= ' AND d.category_id = ?';
            $bindings[] = strtolower($categoryId);
        }

        if ($after !== null) {
            $at = $this->db->selectOne("SELECT updated_at, id FROM {$tables->items} WHERE id = ? AND store_id = ?", [strtolower($after), $storeId]);

            if (! $at instanceof stdClass) {
                return ['rows' => [], 'counts' => $this->documentCounts($tables->items, $storeId)];
            }

            $where .= ' AND (d.updated_at, d.id) < (?::timestamptz, ?)';
            $bindings = [...$bindings, (string) $at->updated_at, (string) $at->id];
        }

        $category = $kind === DocumentKind::Post ? 'd.category_id' : 'NULL';

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            "SELECT d.id, d.status, d.published_at, d.updated_at, d.updated_by, d.version, {$category} AS category_id,
                    ar.title AS title_ar, ar.slug AS slug_ar, en.title AS title_en, en.slug AS slug_en
             FROM {$tables->items} d
             JOIN {$tables->texts} ar ON ar.{$tables->owner} = d.id AND ar.locale = 'ar'
             LEFT JOIN {$tables->texts} en ON en.{$tables->owner} = d.id AND en.locale = 'en'
             WHERE {$where} ORDER BY d.updated_at DESC, d.id DESC LIMIT ?",
            [...$bindings, self::PAGE],
        );

        return [
            'rows' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'status' => (string) $row->status,
                'titleAr' => (string) $row->title_ar,
                'titleEn' => Rows::text($row->title_en),
                'addressAr' => $kind->pathPrefix().$row->slug_ar,
                'addressEn' => $row->slug_en === null ? null : $kind->pathPrefix().$row->slug_en,
                'categoryId' => Rows::text($row->category_id),
                'publishedAt' => Rows::moment($row->published_at)?->format(DATE_ATOM),
                'changedAt' => Rows::moment($row->updated_at)?->format(DATE_ATOM),
                'changedBy' => Rows::text($row->updated_by),
                'version' => (int) $row->version,
            ], $rows),
            'counts' => $this->documentCounts($tables->items, $storeId),
        ];
    }

    public function blogCategories(string $storeId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            'SELECT c.id, c.name_ar, c.name_en, c.slug_ar, c.slug_en, c.position, (SELECT count(*) FROM content.blog_posts p WHERE p.category_id = c.id) AS posts
             FROM content.blog_categories c WHERE c.store_id = ? ORDER BY c.position, c.id',
            [strtolower($storeId)],
        );

        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'nameAr' => (string) $row->name_ar,
            'nameEn' => (string) $row->name_en,
            'slugAr' => (string) $row->slug_ar,
            'slugEn' => (string) $row->slug_en,
            'position' => (int) $row->position,
            'posts' => (int) $row->posts,
        ], $rows);
    }

    public function links(string $storeId): array
    {
        $storeId = strtolower($storeId);
        $inJson = static fn (string $column): string => "jsonb_path_query({$column}, 'strict $.**.link') AS l(v) WHERE jsonb_typeof(l.v) = 'string' AND";

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            "SELECT 'footer' AS place, store_id AS id, l.v #>> '{}' AS link FROM content.footers, ".$inJson('columns')." store_id = ?
             UNION ALL SELECT 'home_section', id, l.v #>> '{}' FROM content.home_sections, ".$inJson('settings')." store_id = ?
             UNION ALL SELECT 'home_section', s.id, m.link FROM content.home_sections s JOIN content.home_section_media m ON m.section_id = s.id WHERE s.store_id = ? AND m.link IS NOT NULL
             UNION ALL SELECT 'banner', id, link FROM content.banners WHERE store_id = ? AND link IS NOT NULL
             UNION ALL SELECT 'bar', store_id, link FROM content.bars WHERE store_id = ? AND link IS NOT NULL
             UNION ALL SELECT 'smart_bar', id, link FROM content.smart_bars WHERE store_id = ? AND link IS NOT NULL
             UNION ALL SELECT 'popup', id, link FROM content.popups WHERE store_id = ? AND link IS NOT NULL",
            array_fill(0, 7, $storeId),
        );

        return array_map(static fn (stdClass $row): array => ['place' => (string) $row->place, 'id' => (string) $row->id, 'link' => (string) $row->link], $rows);
    }

    public function campaigns(string $storeId, ?string $tab, ?string $after, ?array $heldStores = null): array
    {
        $storeId = strtolower($storeId);
        $counts = ['all' => 0, 'scheduled' => 0, 'live' => 0, 'ended' => 0];
        $held = $heldStores === null ? null : array_map(strtolower(...), $heldStores);
        // A campaign with a run in a store the reader holds no job in is not theirs to open (§1.7 #5): left out
        // of the rows and the counts alike, in the query, so a page is never short (lesson 69).
        $onlyHeld = static function (Builder $query, string $runs) use ($held): void {
            if ($held !== null) {
                $query->whereNotExists(static fn (Builder $other) => $other->from('content.campaign_runs as o')->whereColumn('o.campaign_id', "{$runs}.campaign_id")->whereNotIn('o.store_id', $held === [] ? [''] : $held));
            }
        };
        $countQuery = $this->db->table('content.campaign_runs as r')->where('r.store_id', $storeId)->groupBy('r.state');
        $onlyHeld($countQuery, 'r');

        foreach ($countQuery->get(['r.state', $this->db->raw('count(*) AS n')]) as $count) {
            /** @var stdClass $count */
            $key = match ((string) $count->state) {
                'SCHEDULED' => 'scheduled',
                'LIVE' => 'live',
                'ENDED', 'CANCELLED' => 'ended',
                default => null,
            };
            $counts['all'] += (int) $count->n;

            if ($key !== null) {
                $counts[$key] += (int) $count->n;
            }
        }

        $query = $this->db->table('content.campaigns as c')
            ->join('content.campaign_runs as r', static fn ($join) => $join->on('r.campaign_id', '=', 'c.id')->where('r.store_id', '=', $storeId))
            ->select(['c.id', 'c.name_ar', 'c.name_en', 'c.theme_id', 'c.version', 'r.id as run_id', 'r.state', 'r.starts_at', 'r.ends_at', 'r.notice_id']);
        $onlyHeld($query, 'r');
        $states = match ($tab) {
            'scheduled' => ['SCHEDULED'],
            'live' => ['LIVE'],
            'ended' => ['ENDED', 'CANCELLED'],
            default => null,
        };

        if ($states !== null) {
            $query->whereIn('r.state', $states);
        }

        if ($after !== null && ! $this->after($query, 'c', 'content.campaigns', $after)) {
            return ['rows' => [], 'counts' => $counts];
        }

        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('c.created_at')->orderByDesc('c.id')->limit(self::PAGE)->get()->all();
        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $runs = [];

        foreach ($ids === [] ? [] : $this->db->table('content.campaign_runs')->whereIn('campaign_id', $ids)->orderBy('store_id')->get(['id', 'campaign_id', 'store_id', 'state']) as $run) {
            /** @var stdClass $run */
            $runs[(string) $run->campaign_id][] = ['runId' => (string) $run->id, 'storeId' => (string) $run->store_id, 'state' => (string) $run->state];
        }

        $themeIds = array_values(array_unique(array_filter(array_map(static fn (stdClass $row): ?string => Rows::text($row->theme_id), $rows))));
        $themes = [];

        foreach ($themeIds === [] ? [] : $this->db->table('content.themes')->whereIn('id', $themeIds)->get(['id', 'name_ar', 'name_en']) as $theme) {
            /** @var stdClass $theme */
            $themes[(string) $theme->id] = ['nameAr' => (string) $theme->name_ar, 'nameEn' => Rows::text($theme->name_en)];
        }

        return [
            'rows' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'nameAr' => (string) $row->name_ar,
                'nameEn' => Rows::text($row->name_en),
                'version' => (int) $row->version,
                'runId' => (string) $row->run_id,
                'state' => (string) $row->state,
                'startsAt' => Rows::moment($row->starts_at)?->format(DATE_ATOM),
                'endsAt' => Rows::moment($row->ends_at)?->format(DATE_ATOM),
                'noticeId' => Rows::text($row->notice_id),
                'runs' => $runs[(string) $row->id] ?? [],
                'theme' => $row->theme_id === null ? null : ($themes[(string) $row->theme_id] ?? null),
            ], $rows),
            'counts' => $counts,
        ];
    }

    public function themes(?string $after): array
    {
        $query = $this->db->table('content.themes as t')->select(['t.id', 't.name_ar', 't.name_en', 't.is_scheduled', 't.starts_at_local', 't.ends_at_local', 't.version']);

        if ($after !== null && ! $this->after($query, 't', 'content.themes', $after)) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('t.created_at')->orderByDesc('t.id')->limit(self::PAGE)->get()->all();
        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $stores = [];
        $parts = [];

        if ($ids !== []) {
            foreach ($this->db->table('content.theme_stores')->whereIn('theme_id', $ids)->orderBy('store_id')->get(['theme_id', 'store_id', 'starts_at', 'ends_at']) as $store) {
                /** @var stdClass $store */
                $stores[(string) $store->theme_id][] = ['storeId' => (string) $store->store_id, 'startsAt' => Rows::moment($store->starts_at), 'endsAt' => Rows::moment($store->ends_at)];
            }

            foreach ($this->db->table('content.theme_parts')->whereIn('theme_id', $ids)->groupBy('theme_id')->get(['theme_id', $this->db->raw('count(*) AS n')]) as $count) {
                /** @var stdClass $count */
                $parts[(string) $count->theme_id] = (int) $count->n;
            }
        }

        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'nameAr' => (string) $row->name_ar,
            'nameEn' => Rows::text($row->name_en),
            'isScheduled' => (bool) $row->is_scheduled,
            'startsLocal' => substr((string) $row->starts_at_local, 0, 16),
            'endsLocal' => substr((string) $row->ends_at_local, 0, 16),
            'version' => (int) $row->version,
            'stores' => $stores[(string) $row->id] ?? [],
            'parts' => $parts[(string) $row->id] ?? 0,
        ], $rows);
    }

    public function themeChoices(DateTimeImmutable $now): array
    {
        $at = Rows::at($now);

        return array_values(array_map(static fn (stdClass $row): array => ['id' => (string) $row->id, 'nameAr' => (string) $row->name_ar, 'nameEn' => Rows::text($row->name_en)], $this->db->table('content.themes as t')
            ->where(static fn ($open) => $open->where('t.is_scheduled', false)->orWhereExists(static fn ($left) => $left->from('content.theme_stores as s')->whereColumn('s.theme_id', 't.id')->where('s.ends_at', '>', $at)))
            ->orderByDesc('t.created_at')->orderByDesc('t.id')->limit(200)->get(['t.id', 't.name_ar', 't.name_en'])->all()));
    }

    public function campaignsOfTheme(string $themeId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => ['id' => (string) $row->id, 'nameAr' => (string) $row->name_ar, 'nameEn' => Rows::text($row->name_en)], $this->db->table('content.campaigns')
            ->where('theme_id', strtolower($themeId))->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get(['id', 'name_ar', 'name_en'])->all()));
    }

    /**
     * A keyset page after the row named, newest first by its `created_at` then its id; false when the row is not there.
     */
    private function after(Builder $query, string $alias, string $table, string $after): bool
    {
        $at = $this->db->table($table)->where('id', strtolower($after))->first(['created_at', 'id']);

        if (! $at instanceof stdClass) {
            return false;
        }

        $query->where(static fn ($older) => $older->where("{$alias}.created_at", '<', $at->created_at)->orWhere(static fn ($same) => $same->where("{$alias}.created_at", '=', $at->created_at)->where("{$alias}.id", '<', $at->id)));

        return true;
    }

    /**
     * @return array<string, int> each tab's count
     */
    private function documentCounts(string $items, string $storeId): array
    {
        $counts = [Publication::Published->value => 0, Publication::Draft->value => 0];

        foreach ($this->db->select("SELECT status, count(*) AS n FROM {$items} WHERE store_id = ? GROUP BY status", [$storeId]) as $count) {
            /** @var stdClass $count */
            $counts[(string) $count->status] = (int) $count->n;
        }

        return $counts;
    }
}
