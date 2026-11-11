<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\AdvanceCampaigns;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Model\CampaignRun;
use Modules\Content\Domain\Model\RunState;
use Modules\Content\Domain\Repository\Campaigns;
use Modules\Content\Domain\Service\CampaignAlerts;
use Modules\Content\Public\Events\CampaignExpiring;
use Modules\Content\Public\Events\CampaignWentLive;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Psr\Log\LoggerInterface;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;
use Throwable;

/**
 * One tick of the campaigns' clock, every minute from one server (content.md §1.7 #9, #11):
 *
 * - **Going live**: a scheduled run whose start has come, **in a store that is on**, is made live by one conditional
 *   update (`SET state = LIVE WHERE id = ? AND state = SCHEDULED`); only the update that changed the row writes
 *   `CampaignWentLive` - with the run's notice - into the outbox, in that transaction, so a retry or a second job fires
 *   nothing. A run of an off store waits, with no notice and no alerts (Q17 (a)).
 * - **Ending**: a scheduled or live run whose end has come ends, with no event (Pricing stops its prices on its own
 *   timed tasks, so a late tick here never delays a price); its categories and brands are let go.
 * - **The ending alerts** by §1.7 #11's table (`CampaignAlerts`), each its own `CampaignExpiring` with its own
 *   `eventId`, written by the conditional update on its stamp, in the transaction that stamps it, so it goes once. A
 *   live run whose store is off sends none; it is decided as "late" when the store is back.
 *
 * **Each run has its own transaction**, so one bad run doesn't stop the rest: the first failure is thrown once the
 * others are done, the job waits on the failed jobs screen, and its retry carries on. At most `BATCH` runs a tick; the
 * next minute takes the rest. Events after commit, through the outbox (`ShouldDispatchAfterCommit`).
 */
final readonly class AdvanceCampaignsHandler
{
    public const string PERMISSION = ContentPermissions::CAMPAIGNS_ADVANCE;

    /** Runs handled in one tick: far above what a minute brings. */
    public const int BATCH = 200;

    public function __construct(
        private Authorizer $authorizer,
        private Campaigns $campaigns,
        private PlatformApi $platform,
        private Outbox $outbox,
        private ConnectionInterface $db,
        private LoggerInterface $log,
        private ContentCache $cache,
    ) {}

    /**
     * @return int the runs this tick changed or alerted for
     *
     * @throws Unauthorized|Throwable
     */
    public function handle(AdvanceCampaigns $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $now = Moments::now();
        $on = array_map(static fn (StoreDto $store): string => $store->id, $this->platform->stores());
        $failed = null;
        $changed = 0;

        foreach ($this->campaigns->due($now, $on, self::BATCH) as $run) {
            try {
                $changed += $this->advance($run, $now, in_array($run->storeId, $on, true)) ? 1 : 0;
            } catch (Throwable $error) {
                $this->log->error('A campaign run could not advance; the others carried on.', ['run_id' => $run->id, 'error' => $error::class]);
                $failed ??= $error;
            }
        }

        if ($failed !== null) {
            throw $failed;
        }

        return $changed;
    }

    /**
     * One run, in its own transaction. **Everything is decided from the run's row locked here**, never from the copy
     * `due()` read before (review of #382): a notice chosen or an end moved since then is what goes out, and a run
     * cancelled, ended or changed meanwhile is left as its new state says. The conditional updates stay behind it.
     */
    private function advance(CampaignRun $due, DateTimeImmutable $now, bool $storeOn): bool
    {
        return $this->db->transaction(function () use ($due, $now, $storeOn): bool {
            $run = $this->campaigns->lockRun($due->id);

            if ($run === null || ($run->state !== RunState::Scheduled && $run->state !== RunState::Live)) {
                return false;
            }

            if ($run->endsAt <= $now) {
                if (! $this->campaigns->endOnTime($run->id, $now)) {
                    return false;
                }

                $this->campaigns->keepTargets($run->id, [], []);

                return true;
            }

            if (! $storeOn) {
                return false;
            }

            $changed = false;

            if ($run->state === RunState::Scheduled) {
                if (! $this->campaigns->goLive($run->id, $now)) {
                    return false;
                }

                $this->outbox->record(new CampaignWentLive((string) Str::uuid(), $run->id, $run->storeId, $run->noticeId, $now));
                // Its smart bar shows from now (content.md §1.9, "while the run is live"): the shop's frame copy goes.
                $this->cache->invalidate();
                $changed = true;
                // Live now: a late one is judged at once for its alerts (§1.7 #11, "goes live late").
                $run = $this->campaigns->lockRun($run->id) ?? $run;
            }

            foreach (CampaignAlerts::due($run, $now) as $hours) {
                if ($this->campaigns->stampWarning($run->id, $hours, $now)) {
                    $this->outbox->record(new CampaignExpiring((string) Str::uuid(), $run->id, $run->storeId, $run->endsAt, $now));
                    $changed = true;
                }
            }

            return $changed;
        }, 3);
    }
}
