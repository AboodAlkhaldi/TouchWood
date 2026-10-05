<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\BringInImportProducts;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportArchives;
use Modules\Catalog\Application\Import\ImportBringer;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\ImportStepFailed;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Throwable;

/**
 * **Bringing an import's products in, from the queue** (catalog.md §1.12, page part 3): one
 * transaction, **all or nothing**. The products' lock first, then the lists' — categories, brands,
 * attributes, warranties: the order every change takes them in, so none waits on this one in a
 * circle. The work is `ImportBringer`'s; when it ends the import is in, audited, and the zip let go.
 *
 * When anything refuses, nothing is kept — the photos added are removed with the rollback — and the
 * import **failed**, with where and why for its page, audited: the Super Admin mends it and starts it
 * again. A failure the domain did not give (a fault) is also left on the failed jobs screen.
 */
final readonly class BringInImportProductsHandler
{
    /** Reserved: the system's, on the Super Admin's behalf (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    /** The order every change takes the locks in (catalog.md §5; amendment 4(k)). */
    private const array LOCKS = [ListLocks::PRODUCTS, ListLocks::CATEGORIES, ListLocks::BRANDS, ListLocks::ATTRIBUTES, ListLocks::WARRANTIES];

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private ImportArchives $archives,
        private ImportBringer $bringer,
        private ListLocks $locks,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|Throwable a fault, once the failure is recorded
     */
    public function handle(BringInImportProducts $command): void
    {
        try {
            // Inside: a refusal, this one included, leaves the import failed with why, never stuck.
            $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

            // One attempt: a second would fail the same way, and the page says why.
            $archive = $this->db->transaction(function () use ($command): ?string {
                foreach (self::LOCKS as $list) {
                    $this->locks->lock($list);
                }

                $import = $this->imports->lock($command->importId);

                // Brought in already, failed, or never started: nothing to do.
                if ($import === null || $import->state !== ImportHeader::BRINGING_IN) {
                    return null;
                }

                $now = ['state' => ImportHeader::IN, ...array_change_key_case($this->bringer->bringIn($import))];
                $this->imports->finish($import->id);
                $this->platform->recordAudit(ListAudit::changed('import', 'brought_in', $import->id, [...array_fill_keys(array_keys($now), null), 'state' => $import->state], $now) ?? throw new LogicException('No change to record.'));

                return $import->archive;
            });
        } catch (Throwable $error) {
            $failed = $error instanceof ImportStepFailed ? $error : new ImportStepFailed('bringing in', $error);
            $this->recordFailure($command->importId, $failed->reason());

            if (! $failed->getPrevious() instanceof DomainError) {
                throw $error;
            }

            return;
        }

        if ($archive !== null) {
            $this->archives->forget($archive);
        }
    }

    /**
     * The queue gave up on the job before it ended — killed, timed out, or let go by a worker —
     * so the import is not left bringing in for good. It waits for the import's row: a job still
     * running holds it, and the state it leaves is then kept.
     */
    public function stopped(string $importId): void
    {
        $this->recordFailure($importId, 'bringing in: the work stopped before it ended; the failed jobs screen has its details');
    }

    private function recordFailure(string $importId, string $reason): void
    {
        $this->db->transaction(function () use ($importId, $reason): void {
            $import = $this->imports->lock($importId);

            if ($import !== null && $import->state === ImportHeader::BRINGING_IN) {
                $this->imports->fail($import->id, $reason);
                $this->platform->recordAudit(ListAudit::changed('import', 'failed', $import->id, ['state' => $import->state, 'failure' => null], ['state' => ImportHeader::FAILED, 'failure' => mb_substr($reason, 0, 2000)]) ?? throw new LogicException('No change to record.'));
            }
        }, 3);
    }
}
