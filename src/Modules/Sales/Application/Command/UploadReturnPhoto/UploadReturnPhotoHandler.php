<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\UploadReturnPhoto;

use finfo;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Sales\Application\Return\OwnReturnAccess;
use Modules\Sales\Application\Return\ReturnChanges;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\ReturnNotAllowed;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Returns;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/**
 * A photo of the pieces on the customer's own return (sales.md §1.8): **up to 3**, JPEG or PNG — by what the
 * file is, never its name — private, for staff (Platform's `uploadMediaFor`; a media use that blocks
 * deleting, handoff §5.5). While the return waits for staff only (my assumption, stated for the owner to
 * reject); a fourth is `ReturnNotAllowed` (§7). The order locked first.
 */
final readonly class UploadReturnPhotoHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_MANAGE_OWN;

    /** The most photos a return holds (owner, 2026-10-10). */
    public const int MOST = 3;

    /** What a photo may be. */
    public const array TYPES = ['image/jpeg', 'image/png'];

    public function __construct(
        private OwnReturnAccess $access,
        private Returns $returns,
        private ReturnChanges $changes,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return string the photo's media id
     *
     * @throws Unauthorized|InvalidSalesAttribute|ReturnNotFound|InvalidOrderChange|ReturnNotAllowed
     * @throws DomainError Platform's own refusals of the file (`platform.media_too_large`, `…unsupported_media_type`,
     *                     `…invalid_media_attribute` — an empty file)
     */
    public function handle(UploadReturnPhoto $command): string
    {
        // The file first: whether an id is a return is never told apart by a bad file's answer.
        $type = is_file($command->path) ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($command->path) : '';

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidSalesAttribute('file', 'a JPEG or PNG');
        }

        [$returnId, $store, $customerId] = $this->access->authorize(self::PERMISSION, $command->returnId);

        return $this->db->transaction(function () use ($returnId, $store, $customerId, $command): string {
            [, $return] = $this->changes->lock($returnId, $customerId, [ReturnState::REQUESTED]);
            $held = $this->returns->photos($return->id);

            if ($held >= self::MOST) {
                throw new ReturnNotAllowed;
            }

            $mediaId = $this->platform->uploadMediaFor(new ModuleUploadDto(
                'sales', self::PERMISSION, PermissionScope::store($store), MediaVisibility::Private, $command->path, $command->originalFilename,
            ));
            $this->returns->addPhoto($return->id, $mediaId, $held + 1);

            return $mediaId;
        }, 3);
    }
}
