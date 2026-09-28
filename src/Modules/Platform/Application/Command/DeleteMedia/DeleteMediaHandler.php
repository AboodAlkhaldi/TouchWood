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
use Modules\Platform\Application\Media\PrivateMedia;
use Modules\Platform\Domain\Exception\InvalidMediaAttribute;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Dto\MediaUseDto;
use Modules\Platform\Public\Enums\MediaVisibility;
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
 *
 * A module deleting a file it holds for its own use (B2B step 3, amendments 4 and 5) goes through
 * the same delete, with three differences: its own permission is checked instead of the media one,
 * only a private file may go, and **any** use refuses it — another module's use is never detached
 * on its behalf. Staff deletion is unchanged (platform.md §9.4), except that a private file does not
 * exist for staff who may not see private files (b2b.md amendment 8(a)).
 */
final readonly class DeleteMediaHandler
{
    public const string PERMISSION = PlatformPermissions::MEDIA_DELETE;

    public function __construct(
        private Authorizer $authorizer,
        private MediaRepository $media,
        private InMemoryMediaUsages $usages,
        private PrivateMedia $private,
        private MediaStorage $storage,
        private AuditLog $auditLog,
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    /**
     * Ordinarily the media permission; for a module deleting a file it holds, that module's own
     * permission in its own scope — the mirror of UploadMediaHandler::authorizeUpload.
     *
     * The permission must belong to the module that names it — Platform checks the prefix, which is
     * all it can do without reading Access's catalog it sits below. An undeclared name is refused
     * by the authorizer itself. Both come before the id is looked at, so a caller without the right
     * learns nothing about whether the file exists.
     */
    private function authorizeDelete(DeleteMedia $command): void
    {
        $delete = $command->forModule;

        if ($delete === null) {
            $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

            return;
        }

        if (! str_starts_with($delete->permission, $delete->module.'.')) {
            throw new InvalidMediaAttribute('permission', "\"{$delete->permission}\" does not belong to the \"{$delete->module}\" module");
        }

        $this->authorizer->authorize($delete->permission, $delete->scope);
    }

    public function handle(DeleteMedia $command): void
    {
        $this->authorizeDelete($command);

        // Up to three attempts: a module attaching this media while it is deleted can deadlock with
        // the delete, and PostgreSQL then cancels one of them. Nothing outside the database happens
        // before commit, so running again is safe.
        $this->db->transaction(function () use ($command) {
            // Locked first: a module adding a reference to this media now waits for the delete.
            $media = $this->media->lockById($command->mediaId) ?? throw new MediaNotFound($command->mediaId);
            $forModule = $command->forModule;

            // Before anything about its uses is said: a refusal that named the application holding
            // a company's paper would tell staff it is there (amendment 8(a)).
            if ($forModule === null) {
                $this->private->reach($media);
            }

            // A module deletes only the private files it holds (amendment 5): a public image may be
            // shared by anything that uploaded the same picture, so it is never one module's to remove.
            if ($forModule !== null && $media->visibility() !== MediaVisibility::Private) {
                throw new InvalidMediaAttribute('visibility', 'a module deletes only a private file');
            }

            $found = [];

            foreach ($this->usages->all() as $usage) {
                $uses = $usage->usesOf($media->id());

                if ($uses !== []) {
                    $found[] = [$usage, $uses];
                }
            }

            $all = array_merge([], ...array_column($found, 1));

            // A module deletes a file only once nothing uses it — its own use included. It never
            // detaches another module's use: that is someone else's record (amendment 5).
            if ($forModule !== null && $all !== []) {
                throw new MediaInUse($media->id(), $all);
            }

            $blocking = array_values(array_filter($all, fn (MediaUseDto $use): bool => $use->blocksDelete));

            if ($blocking !== []) {
                throw new MediaInUse($media->id(), $blocking);
            }

            // Each module checks its own permission for its change, and audits it (MediaUsage).
            foreach ($found as [$usage]) {
                $usage->detach($media->id());
            }

            $this->media->delete($media);
            $this->auditLog->record(MediaAudit::deleted($media, $all, $forModule?->module, $forModule?->permission));
            $this->storage->deleteAfterCommit($media);
            $this->events->dispatch(new MediaDeleted((string) Str::uuid(), $media->id(), CarbonImmutable::now()));
        }, 3);
    }
}
