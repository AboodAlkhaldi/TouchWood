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
use Modules\Platform\Application\Settings\ReadSetting;
use Modules\Platform\Domain\Exception\InvalidMediaAttribute;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Domain\Repository\MediaRepository;
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
 * Identical public images return the existing media instead of a duplicate; private files are
 * never shared. The file is written to storage before the row, and removed again if the row does
 * not commit — also when an outer transaction rolls back later — so every file has its row and
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
     */
    private function authorizeUpload(UploadMedia $command): void
    {
        $upload = $command->forModule;

        if ($upload === null) {
            $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

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

        if ($existing !== null) {
            return $existing->id();
        }

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

        return $this->media->publicByChecksum($file->checksum)?->id()
            ?? throw new RuntimeException('An identical image uploaded at the same moment was deleted before it could be returned. Upload the file again.');
    }
}
