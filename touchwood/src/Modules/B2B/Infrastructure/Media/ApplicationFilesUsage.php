<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Media;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Dto\MediaUseDto;

/**
 * A company's papers are Platform media, and an application is a permanent record (b2b.md §1.4):
 * every application holding a file — as a document, or as the answer to a request — is a
 * **blocking** use, drafts included. So deleting the file through the media library is refused
 * while any application holds it, and detaching is never asked for.
 *
 * B2B lets go of its own files through its own use cases (a replaced upload, a discarded draft),
 * which delete a file only once no application holds it.
 */
final readonly class ApplicationFilesUsage implements MediaUsage
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function usesOf(string $mediaId): array
    {
        $documents = $this->db->table('b2b.application_documents')->select('application_id')->where('media_id', $mediaId);

        $applicationIds = $this->db->table('b2b.application_request_answers')->select('application_id')->where('media_id', $mediaId)
            // UNION, not UNION ALL: an application holding the file twice is still one use.
            ->union($documents)
            ->orderBy('application_id')
            ->pluck('application_id');

        $uses = [];

        foreach ($applicationIds as $applicationId) {
            $uses[] = new MediaUseDto('b2b.application', (string) $applicationId, true);
        }

        return $uses;
    }

    public function detach(string $mediaId): void
    {
        // Every use blocks, so Platform never gets here; reaching it means that rule was broken.
        throw new LogicException("A file an application holds is never detached from it ({$mediaId}).");
    }
}
