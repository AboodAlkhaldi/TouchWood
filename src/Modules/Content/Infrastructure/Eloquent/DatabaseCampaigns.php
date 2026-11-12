<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Modules\Content\Domain\Exception\NoticeTaken;
use Modules\Content\Domain\Model\Campaign;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\ValueObject\PriceChoices;
use Modules\Content\Domain\ValueObject\Slug;
use stdClass;

/** `Campaigns` in SQL (content.md §5: `content.campaigns`, `content.campaign_runs`, `content.campaign_run_targets`). */
final readonly class DatabaseCampaigns implements Campaigns
{
    private const string COLUMNS = 'id, name_ar, name_en, starts_at_local, ends_at_local, theme_id, version, updated_by, updated_at';

    private const string RUN_COLUMNS = 'id, campaign_id, store_id, state, starts_at, ends_at, price_choices, notice_id, went_live_at, ended_at, cancelled_at, warned_72_at, warned_24_at, slug_ar, slug_en';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?Campaign
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.campaigns WHERE id = ?', [strtolower($id)]);

        return $row instanceof stdClass ? self::campaign($row) : null;
    }

    public function lock(string $id): ?Campaign
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.campaigns WHERE id = ? FOR UPDATE', [strtolower($id)]);

        return $row instanceof stdClass ? self::campaign($row) : null;
    }

    public function add(Campaign $campaign): void
    {
        ForeignKeyRefusals::guard(function () use ($campaign): void {
            $this->db->table('content.campaigns')->insert([
                'id' => $campaign->id,
                'created_by' => $campaign->updatedBy,
                'created_at' => Rows::at($campaign->updatedAt),
                ...self::written($campaign),
            ]);
        });
    }

    public function update(Campaign $campaign): void
    {
        ForeignKeyRefusals::guard(function () use ($campaign): void {
            $this->db->table('content.campaigns')->where('id', $campaign->id)->update(self::written($campaign));
        });
    }

    public function delete(string $id): void
    {
        $this->db->table('content.campaigns')->where('id', strtolower($id))->delete();
    }

    public function runsOf(string $campaignId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT '.self::RUN_COLUMNS.' FROM content.campaign_runs WHERE campaign_id = ? ORDER BY store_id', [strtolower($campaignId)]);

        return array_map(self::run(...), $rows);
    }

    public function lockRuns(string $campaignId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT '.self::RUN_COLUMNS.' FROM content.campaign_runs WHERE campaign_id = ? ORDER BY store_id FOR UPDATE', [strtolower($campaignId)]);

        return array_map(self::run(...), $rows);
    }

    public function findRun(string $runId): ?CampaignRun
    {
        $row = $this->db->selectOne('SELECT '.self::RUN_COLUMNS.' FROM content.campaign_runs WHERE id = ?', [strtolower($runId)]);

        return $row instanceof stdClass ? self::run($row) : null;
    }

    public function lockRun(string $runId): ?CampaignRun
    {
        $row = $this->db->selectOne('SELECT '.self::RUN_COLUMNS.' FROM content.campaign_runs WHERE id = ? FOR UPDATE', [strtolower($runId)]);

        return $row instanceof stdClass ? self::run($row) : null;
    }

    public function addRun(CampaignRun $run): void
    {
        self::noticeGuard(function () use ($run): void {
            $now = Rows::at(CarbonImmutable::now()->toDateTimeImmutable());
            $this->db->table('content.campaign_runs')->insert([
                'id' => $run->id,
                'campaign_id' => $run->campaignId,
                'store_id' => $run->storeId,
                'created_at' => $now,
                ...self::runWritten($run),
            ]);
        });
    }

    public function updateRun(CampaignRun $run): void
    {
        self::noticeGuard(function () use ($run): void {
            $this->db->table('content.campaign_runs')->where('id', $run->id)->update(self::runWritten($run));
        });
    }

    public function deleteRun(string $runId): void
    {
        $this->db->table('content.campaign_runs')->where('id', strtolower($runId))->delete();
    }

    public function namesOf(array $runIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (string $id): string => strtolower(trim($id)), $runIds), static fn (string $id): bool => preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1)));

        if ($ids === []) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            'SELECT r.id, r.store_id, r.state, c.name_ar, c.name_en FROM content.campaign_runs r JOIN content.campaigns c ON c.id = r.campaign_id WHERE r.id IN ('.implode(', ', array_fill(0, count($ids), '?')).')',
            $ids,
        );
        $named = [];

        foreach ($rows as $row) {
            $named[(string) $row->id] = ['storeId' => (string) $row->store_id, 'state' => RunState::from((string) $row->state), 'nameAr' => (string) $row->name_ar, 'nameEn' => Rows::text($row->name_en)];
        }

        return $named;
    }

    public function noticeHolder(string $noticeId): ?string
    {
        $id = $this->db->scalar("SELECT id FROM content.campaign_runs WHERE notice_id = ? AND state IN ('DRAFT', 'SCHEDULED')", [strtolower($noticeId)]);

        return $id === null ? null : (string) $id;
    }

    public function keepTargets(string $runId, array $categoryIds, array $brandIds): void
    {
        $runId = strtolower($runId);
        $this->db->table('content.campaign_run_targets')->where('run_id', $runId)->delete();
        $rows = [
            ...array_map(static fn (string $id): array => ['run_id' => $runId, 'category_id' => strtolower($id), 'brand_id' => null], array_values(array_unique($categoryIds))),
            ...array_map(static fn (string $id): array => ['run_id' => $runId, 'category_id' => null, 'brand_id' => strtolower($id)], array_values(array_unique($brandIds))),
        ];

        if ($rows !== []) {
            ForeignKeyRefusals::guard(fn () => $this->db->table('content.campaign_run_targets')->insert($rows));
        }
    }

    public function due(DateTimeImmutable $now, array $onStores, int $limit): array
    {
        $at = Rows::at($now);
        $stores = $onStores === [] ? 'NULL' : implode(', ', array_fill(0, count($onStores), '?'));
        $on = array_map(strtolower(...), $onStores);

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            'SELECT '.self::RUN_COLUMNS." FROM content.campaign_runs
             WHERE (state IN ('SCHEDULED', 'LIVE') AND ends_at <= ?::timestamptz)
                OR (store_id IN ({$stores}) AND (
                    (state = 'SCHEDULED' AND starts_at <= ?::timestamptz)
                    OR (state = 'LIVE' AND warned_24_at IS NULL AND ends_at - starts_at >= interval '24 hours' AND ends_at - interval '24 hours' <= ?::timestamptz)
                    OR (state = 'LIVE' AND warned_72_at IS NULL AND ends_at - starts_at >= interval '72 hours' AND ends_at - interval '72 hours' <= ?::timestamptz AND ends_at - ?::timestamptz > interval '24 hours')
                ))
             ORDER BY starts_at, id LIMIT ?",
            [$at, ...$on, $at, $at, $at, $at, max(1, $limit)],
        );

        return array_map(self::run(...), $rows);
    }

    public function goLive(string $runId, DateTimeImmutable $now): bool
    {
        $at = Rows::at($now);

        return $this->db->update(
            "UPDATE content.campaign_runs SET state = 'LIVE', went_live_at = ?::timestamptz, updated_at = ?::timestamptz
             WHERE id = ? AND state = 'SCHEDULED' AND starts_at <= ?::timestamptz AND ends_at > ?::timestamptz",
            [$at, $at, strtolower($runId), $at, $at],
        ) === 1;
    }

    public function endOnTime(string $runId, DateTimeImmutable $now): bool
    {
        $at = Rows::at($now);

        return $this->db->update(
            "UPDATE content.campaign_runs SET state = 'ENDED', ended_at = ends_at, updated_at = ?::timestamptz
             WHERE id = ? AND state IN ('SCHEDULED', 'LIVE') AND ends_at <= ?::timestamptz",
            [$at, strtolower($runId), $at],
        ) === 1;
    }

    public function stampWarning(string $runId, int $hours, DateTimeImmutable $now): bool
    {
        $column = match ($hours) {
            72 => 'warned_72_at',
            24 => 'warned_24_at',
            default => throw new InvalidArgumentException('An ending alert is 72 or 24 hours before the end.'),
        };
        $at = Rows::at($now);

        return $this->db->update(
            "UPDATE content.campaign_runs SET {$column} = ?::timestamptz, updated_at = ?::timestamptz WHERE id = ? AND state = 'LIVE' AND {$column} IS NULL",
            [$at, $at, strtolower($runId)],
        ) === 1;
    }

    /**
     * A notice another run took between the check and the write (the partial unique index): told as the check would.
     *
     * @param  callable(): void  $write
     *
     * @throws NoticeTaken
     */
    private static function noticeGuard(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $failed) {
            if ((string) $failed->getCode() === '23505' && str_contains($failed->getMessage(), 'campaign_runs_notice')) {
                throw new NoticeTaken;
            }

            throw $failed;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(Campaign $campaign): array
    {
        return [
            'name_ar' => $campaign->nameAr,
            'name_en' => $campaign->nameEn,
            'starts_at_local' => $campaign->startsLocal.':00',
            'ends_at_local' => $campaign->endsLocal.':00',
            'theme_id' => $campaign->themeId,
            'version' => $campaign->version,
            'updated_by' => $campaign->updatedBy,
            'updated_at' => Rows::at($campaign->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function runWritten(CampaignRun $run): array
    {
        return [
            'state' => $run->state->value,
            'starts_at' => Rows::at($run->startsAt),
            'ends_at' => Rows::at($run->endsAt),
            'price_choices' => $run->priceChoices === null ? null : Rows::json($run->priceChoices->toArray()),
            'notice_id' => $run->noticeId,
            'went_live_at' => Rows::at($run->wentLiveAt),
            'ended_at' => Rows::at($run->endedAt),
            'cancelled_at' => Rows::at($run->cancelledAt),
            'warned_72_at' => Rows::at($run->warned72At),
            'warned_24_at' => Rows::at($run->warned24At),
            'updated_at' => Rows::at(CarbonImmutable::now()->toDateTimeImmutable()),
        ];
    }

    private static function campaign(stdClass $row): Campaign
    {
        return new Campaign(
            (string) $row->id,
            (string) $row->name_ar,
            Rows::text($row->name_en),
            substr((string) $row->starts_at_local, 0, 16),
            substr((string) $row->ends_at_local, 0, 16),
            Rows::text($row->theme_id),
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at) ?? CarbonImmutable::now()->toDateTimeImmutable(),
        );
    }

    private static function run(stdClass $row): CampaignRun
    {
        return new CampaignRun(
            (string) $row->id,
            (string) $row->campaign_id,
            (string) $row->store_id,
            RunState::from((string) $row->state),
            Rows::moment($row->starts_at) ?? CarbonImmutable::now()->toDateTimeImmutable(),
            Rows::moment($row->ends_at) ?? CarbonImmutable::now()->toDateTimeImmutable(),
            $row->price_choices === null ? null : PriceChoices::of(Rows::decode($row->price_choices)),
            Rows::text($row->notice_id),
            Rows::moment($row->went_live_at),
            Rows::moment($row->ended_at),
            Rows::moment($row->cancelled_at),
            Rows::moment($row->warned_72_at),
            Rows::moment($row->warned_24_at),
            Rows::text($row->slug_ar ?? null),
            Rows::text($row->slug_en ?? null),
        );
    }

    public function giveAddress(string $runId, string $storeId, string $slugAr, string $slugEn): void
    {
        $runId = strtolower($runId);
        $storeId = strtolower($storeId);
        $free = function (string $column, string $slug) use ($storeId, $runId): string {
            /** @var list<string> $taken */
            $taken = $this->db->table('content.campaign_runs')->where('store_id', $storeId)->where('id', '<>', $runId)
                ->where(static fn ($query) => $query->where($column, $slug)->orWhere($column, 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $slug).'-%'))
                ->pluck($column)->map(static fn (mixed $value): string => (string) $value)->all();
            $candidate = $slug;

            // At most as many tries as addresses taken, plus one: the loop has its end.
            for ($n = 2; in_array($candidate, $taken, true) && $n <= count($taken) + 2; $n++) {
                $candidate = mb_substr($slug, 0, Slug::MAX - 6)."-{$n}";
            }

            return $candidate;
        };

        $this->db->table('content.campaign_runs')->where('id', $runId)->whereNull('slug_ar')->update(['slug_ar' => $free('slug_ar', $slugAr), 'slug_en' => $free('slug_en', $slugEn)]);
    }
}
