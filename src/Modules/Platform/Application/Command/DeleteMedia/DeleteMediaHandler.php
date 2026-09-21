<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeleteMedia;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\Audit\MediaAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Media\InMemoryMediaUsages;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Dto\MediaUseDto;
use Modules\Platform\Public\Events\MediaDeleted;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Deletes media, detaching it from every module that uses it — or refusing, when any use blocks
 * it (owner's decision, 2026-09-18). Everything happens in one transaction: the modules' changes,
 * the delete and the audit entry commit together or not at all. Other modules' foreign keys with
 * ON DELETE RESTRICT remain the backstop: a reference a module failed to remove becomes MediaInUse.
 * The files go only once the delete commits.
 */
final readonly class DeleteMediaHandler
{
    public const string PERMISSION = PlatformPermissions::MEDIA_DELETE;

    public function __construct(
        private Authorizer $authorizer,
        private MediaRepository $media,
        private InMemoryMediaUsages $usages,
        private MediaStorage $storage,
        private AuditLog $auditLog,
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    public function handle(DeleteMedia $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        // Up to three attempts: a module attaching this media while it is deleted can deadlock with
        // the delete, and PostgreSQL then cancels one of them. Nothing outside the database happens
        // before commit, so running again is safe.
        $this->db->transaction(function () use ($command) {
            // Locked first: a module adding a reference to this media now waits for the delete.
            $media = $this->media->lockById($command->mediaId) ?? throw new MediaNotFound($command->mediaId);

            $found = [];

            foreach ($this->usages->all() as $usage) {
                $uses = $usage->usesOf($media->id());

                if ($uses !== []) {
                    $found[] = [$usage, $uses];
                }
            }

            $all = array_merge([], ...array_column($found, 1));
            $blocking = array_values(array_filter($all, fn (MediaUseDto $use): bool => $use->blocksDelete));

            if ($blocking !== []) {
                throw new MediaInUse($media->id(), $blocking);
            }

            // Each module checks its own permission for its change, and audits it (MediaUsage).
            foreach ($found as [$usage]) {
                $usage->detach($media->id());
            }

            $this->media->delete($media);
            $this->auditLog->record(MediaAudit::deleted($media, $all));
            $this->storage->deleteAfterCommit($media);
            $this->events->dispatch(new MediaDeleted((string) Str::uuid(), $media->id(), CarbonImmutable::now()));
        }, 3);
    }
}
