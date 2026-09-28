<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\UpdateMediaAltText;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\MediaAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Media\PrivateMedia;
use Modules\Platform\Domain\Exception\MediaNotFound;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A private file is described only by someone who may see it, and to anyone else it does not
 * exist (b2b.md amendment 8(a)).
 */
final readonly class UpdateMediaAltTextHandler
{
    public const string PERMISSION = PlatformPermissions::MEDIA_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private MediaRepository $media,
        private PrivateMedia $private,
        private AuditLog $auditLog,
        private ConnectionInterface $db,
    ) {}

    public function handle(UpdateMediaAltText $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command) {
            $media = $this->media->lockById($command->mediaId) ?? throw new MediaNotFound($command->mediaId);
            $this->private->reach($media);
            $before = ['alt_ar' => $media->altAr(), 'alt_en' => $media->altEn()];

            $media->changeAltText($command->altAr, $command->altEn);
            $changed = $media->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->media->update($media);
            $this->auditLog->record(MediaAudit::altTextChanged($media, $before, $changed));
        });
    }
}
