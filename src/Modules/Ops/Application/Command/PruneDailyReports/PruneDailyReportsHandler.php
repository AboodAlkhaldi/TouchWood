<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\PruneDailyReports;

use Carbon\CarbonImmutable;
use Modules\Ops\Application\DailyReport\ReportFiles;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Repository\DailyReports;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The daily reports keep twelve months** (ops.md §5; owner, 2026-10-28): the rows go, then their
 * files. The system's, under the same reserved permission as the rest of Ops' removals.
 */
final readonly class PruneDailyReportsHandler
{
    public const string PERMISSION = OpsPermissions::LOG_PRUNE;

    public const int MONTHS = 12;

    public function __construct(
        private Authorizer $authorizer,
        private DailyReports $reports,
        private ReportFiles $files,
    ) {}

    /**
     * @return int how many went
     *
     * @throws Unauthorized
     */
    public function handle(PruneDailyReports $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        // The rows first: a file left behind by a failure is harmless, a row naming no file is not.
        $files = $this->reports->prune(CarbonImmutable::now()->subMonthsNoOverflow(self::MONTHS)->toDateTimeImmutable());
        $this->files->delete($files);

        return count($files);
    }
}
