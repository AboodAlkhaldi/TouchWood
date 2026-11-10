<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\UploadMedia;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\MediaAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Media\MediaInspector;
use Modules\Platform\Application\Media\MediaSettings;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Application\Media\MediaVariantsQueue;
use Modules\Platform\Application\Media\MediaVariantsRetry;
use Modules\Platform\Application\Settings\ReadSetting;
use Modules\Platform\Domain\Exception\InvalidMediaAttribute;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Platform\Public\PlatformPermissions;
use RuntimeException;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Throwable;

/**
 * Stores an uploaded file (Platform spec §1.4).
 *
 * Identical public images return the existing media instead of a duplicate — and one whose sizes
 * failed has them queued again (owner 2026-11-10, P10 (a)); private files are never shared. The
 * file is written to storage before the row, and removed again if the row does not commit — also when an outer transaction rolls back later — so every file has its row and
 * every row has its file.
 */
final readonly class UploadMediaHandler
{
    public const string PERMISSION = PlatformPermissions::MEDIA_UPLOAD;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private MediaInspector $inspector,
        private MediaStorage $storage,
        private MediaRepository $media,
        private MediaVariantsQueue $variants,
        private MediaVariantsRetry $retry,
        private ReadSetting $settings,
        private AuditLog $auditLog,
        private ConnectionInterface $db,
    ) {}

    /**
     * Ordinarily the media permission; for a module uploading for its own use, that module's own
     * permission in its own scope (stage 2b, P1).
     *
     * The permission must belong to the module that names it — Platform checks the prefix, which is
     * all it can do without reading Access's catalog it sits below. An undeclared name is refused
     * by the authorizer itself, so nothing reaches storage under a permission no module declared.
     *
     * A private file uploaded through the media library also needs the private-files permission
     * (B2B step 3, amendment 6): the library lists private files only to its holders, so nobody
     * else may put one there. A module's upload is checked against that module's permission alone.
     */
    private function authorizeUpload(UploadMedia $command): void
    {
        $upload = $command->forModule;

        if ($upload === null) {
            $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

            if ($command->visibility === MediaVisibility::Private) {
                $this->authorizer->authorize(PlatformPermissions::MEDIA_PRIVATE_VIEW, PermissionScope::global());
            }

            return;
        }

        if (! str_starts_with($upload->permission, $upload->module.'.')) {
            throw new InvalidMediaAttribute('permission', "\"{$upload->permission}\" does not belong to the \"{$upload->module}\" module");
        }

        $this->authorizer->authorize($upload->permission, $upload->scope);
    }

    /**
     * @return string the media id — of the existing media when the same public image was uploaded before
     */
    public function handle(UploadMedia $command): string
    {
        $this->authorizeUpload($command);

        $file = $this->inspector->inspect($command->path);
        $isPublic = $command->visibility === MediaVisibility::Public;

        $existing = $isPublic ? $this->media->publicByChecksum($file->checksum) : null;

        $existingId = $existing === null ? null : $this->existing($existing, $command);

        if ($existingId !== null) {
            return $existingId;
        }

        // None, or it was deleted between the check and its lock: this upload stores it anew.
        $actor = $this->actors->current();

        $media = Media::upload(
            $this->media->nextId(),
            $command->visibility,
            $this->storage->originalsDisk(),
            $command->originalFilename,
            $file->mime,
            $file->bytes,
            ($this->settings)(MediaSettings::maxBytesKey($command->visibility), null)->int(),
            $file->width,
            $file->height,
            $file->animated,
            $file->checksum,
            $actor->type === ActorType::Staff ? $actor->id : null,
            CarbonImmutable::now(),
        );

        $this->storage->putOriginal($media, $command->path);

        try {
            $added = $this->db->transaction(function () use ($media, $command): bool {
                if (! $this->media->add($media)) {
                    return false;
                }

                $this->auditLog->record(MediaAudit::uploaded($media, $command->forModule?->module, $command->forModule?->permission));

                // A caller's own transaction may still roll back after this one commits.
                $this->storage->deleteAfterRollBack($media);

                return true;
            });
        } catch (Throwable $error) {
            // Only failures before the commit land here, so the file never outlives a missing row.
            $this->storage->deleteNow($media);

            throw $error;
        }

        if ($added) {
            // Queued outside the try: if queueing fails, the upload itself has still succeeded and
            // its file must stay. The stuck-variants sweep queues it again later.
            if ($media->hasVariants()) {
                $this->variants->generate($media->id());
            }

            return $media->id();
        }

        // Someone uploaded the same public image between our check and our insert: keep theirs.
        $this->storage->deleteNow($media);

        $winner = $this->media->publicByChecksum($file->checksum);

        return ($winner === null ? null : $this->existing($winner, $command))
            ?? throw new RuntimeException('An identical image uploaded at the same moment was deleted before it could be returned. Upload the file again.');
    }

    /**
     * The same public image, uploaded before: its id. If its sizes failed, uploading it again is how
     * the person asks for them again (owner 2026-11-10, P10 (a)) — "Upload this image again." must
     * work — so they are queued once more, exactly as RetryMediaVariants queues them, audited as that
     * retry marked "by_upload". A PENDING or READY image is left as it is.
     *
     * Only the upload's own permission is checked (authorizeUpload): through the library that is
     * platform.media.upload, the permission Retry needs; a module's upload proves its own instead,
     * and may retry the image it was allowed to upload (platform.md §1.4) — no extra check either way.
     *
     * The status above was read without a lock, so it is read again under the row's lock: two
     * uploads of the same failed image at once queue it once, the second finding it PENDING.
     *
     * @return string|null null when the image was deleted between the check and the lock: the
     *                     caller then stores the file anew, so it never answers a dead id
     */
    private function existing(Media $existing, UploadMedia $command): ?string
    {
        if ($existing->variantsStatus() !== MediaVariantsStatus::Failed) {
            return $existing->id();
        }

        return $this->db->transaction(function () use ($existing, $command): ?string {
            $locked = $this->media->lockById($existing->id());

            if ($locked === null) {
                return null;
            }

            if ($locked->variantsStatus() === MediaVariantsStatus::Failed) {
                $this->retry->retry($locked, byUpload: true, forModule: $command->forModule);
            }

            return $locked->id();
        });
    }
}
