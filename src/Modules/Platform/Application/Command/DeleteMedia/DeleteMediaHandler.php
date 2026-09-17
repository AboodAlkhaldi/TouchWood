<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeleteMedia;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\Audit\MediaAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Events\MediaDeleted;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Deletes media nobody references. Another module's foreign key with ON DELETE RESTRICT makes
 * the database refuse, which becomes MediaInUse. The files go only once the delete commits.
 */
final readonly class DeleteMediaHandler
{
    public const string PERMISSION = 'platform.media.delete';

    public function __construct(
        private Authorizer $authorizer,
        private MediaRepository $media,
        private MediaStorage $storage,
        private AuditLog $auditLog,
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    public function handle(DeleteMedia $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command) {
            $media = $this->media->lockById($command->mediaId) ?? throw new MediaNotFound($command->mediaId);

            $this->media->delete($media);
            $this->auditLog->record(MediaAudit::deleted($media));
            $this->storage->deleteAfterCommit($media);
            $this->events->dispatch(new MediaDeleted((string) Str::uuid(), $media->id(), CarbonImmutable::now()));
        });
    }
}
