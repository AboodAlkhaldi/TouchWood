<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListMedia;

use Carbon\CarbonImmutable;
use Modules\Platform\Application\Media\InMemoryMediaUsages;
use Modules\Platform\Application\Query\MediaReader;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * One page of the media library (frontend.md §3.5, E5).
 *
 * Media belongs to no store — a file is the system's, and its permissions are store-free — so the
 * questions are asked globally. Whoever may add, describe or remove a file may look at the library;
 * there is no separate permission for looking, and nobody else has business here.
 *
 * Each row carries **where the file is used** and whether any of those uses would refuse a delete
 * (platform.md §1.4). That is asked of the modules themselves, and only for the files on this page:
 * the answer is theirs to give, and it changes as they change.
 */
final readonly class ListMediaHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private MediaReader $reader,
        private InMemoryMediaUsages $usages,
    ) {}

    /**
     * @throws Unauthorized when they hold nothing that lets them near the library
     */
    public function handle(ListMedia $query): MediaLibraryPage
    {
        $mayUpload = $this->holds(PlatformPermissions::MEDIA_UPLOAD);
        $mayUpdate = $this->holds(PlatformPermissions::MEDIA_UPDATE);
        $mayDelete = $this->holds(PlatformPermissions::MEDIA_DELETE);

        if (! $mayUpload && ! $mayUpdate && ! $mayDelete) {
            throw new Unauthorized(PlatformPermissions::MEDIA_UPLOAD);
        }

        $perPage = min(max($query->perPage, 1), 100);

        // One more than a page, to learn whether there is another without counting the library.
        $rows = $this->reader->page(new ListMedia($query->cursorCreatedAt, $query->cursorId, $perPage + 1));
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        $media = array_map($this->row(...), $rows);
        $last = $more ? end($media) : null;

        return new MediaLibraryPage(
            $media,
            $last === false || $last === null ? null : $last->uploadedAt,
            $last === false || $last === null ? null : $last->id,
            $mayUpload,
            $mayUpdate,
            $mayDelete,
        );
    }

    private function holds(string $permission): bool
    {
        // Store-free permissions: null means every store, which is the only way to hold one.
        return $this->authorizer->storesWith($permission) !== [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function row(array $row): MediaRow
    {
        $id = (string) $row['id'];
        $status = $row['variants_status'] === null
            ? null
            : MediaVariantsStatus::from((string) $row['variants_status']);

        $uses = [];
        $blocked = false;

        foreach ($this->usages->all() as $usage) {
            foreach ($usage->usesOf($id) as $use) {
                $uses[] = $use->describe();
                $blocked = $blocked || $use->blocksDelete;
            }
        }

        return new MediaRow(
            $id,
            (string) $row['original_filename'],
            (string) $row['mime'],
            (int) $row['bytes'],
            $row['width'] === null ? null : (int) $row['width'],
            $row['height'] === null ? null : (int) $row['height'],
            MediaVisibility::from((string) $row['visibility']),
            $status,
            $this->retryable($status, $row['variants_queued_at']),
            (string) $row['created_at'],
            $row['alt_ar'] === null ? null : (string) $row['alt_ar'],
            $row['alt_en'] === null ? null : (string) $row['alt_en'],
            $uses,
            $blocked,
        );
    }

    /**
     * A failed image, or one that has been pending long enough to count as lost.
     *
     * The same rule the domain applies when the retry is actually asked for, read from its own
     * constant rather than written out again here (Media::retryVariants).
     */
    private function retryable(?MediaVariantsStatus $status, mixed $queuedAt): bool
    {
        if ($status === MediaVariantsStatus::Failed) {
            return true;
        }

        if ($status !== MediaVariantsStatus::Pending || ! is_string($queuedAt)) {
            return false;
        }

        return CarbonImmutable::parse($queuedAt)
            ->lessThanOrEqualTo(CarbonImmutable::now()->subMinutes(Media::STALE_PENDING_MINUTES));
    }
}
