<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;
use stdClass;

/**
 * Plain query builder rather than an Eloquent model: media rows are simple, and ON CONFLICT
 * DO NOTHING lets a duplicate upload be detected without aborting the transaction.
 */
final readonly class DatabaseMediaRepository implements MediaRepository
{
    private const string TABLE = 'platform.media';

    /** PostgreSQL's code for a foreign-key violation. */
    private const string FOREIGN_KEY_VIOLATION = '23503';

    /** Crockford base32, as Str::ulid() produces. */
    private const string ULID_PATTERN = '/\A[0-9a-hjkmnp-tv-z]{26}\z/i';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function byId(string $id): ?Media
    {
        if (preg_match(self::ULID_PATTERN, $id) !== 1) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($id))->first();

        return $row instanceof stdClass ? $this->toMedia($row) : null;
    }

    public function lockById(string $id): ?Media
    {
        if (preg_match(self::ULID_PATTERN, $id) !== 1) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($id))->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toMedia($row) : null;
    }

    public function publicByChecksum(string $checksum): ?Media
    {
        $row = $this->db->table(self::TABLE)
            ->where('visibility', MediaVisibility::Public->value)
            ->where('checksum', $checksum)
            ->first();

        return $row instanceof stdClass ? $this->toMedia($row) : null;
    }

    public function stalePendingIds(DateTimeImmutable $queuedBefore, int $limit): array
    {
        /** @var list<string> */
        return $this->db->table(self::TABLE)
            ->where('variants_status', MediaVariantsStatus::Pending->value)
            ->where('variants_queued_at', '<=', $queuedBefore)
            ->orderBy('variants_queued_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    public function add(Media $media): bool
    {
        $now = CarbonImmutable::now();

        // The conflict target is the partial unique index on public checksums, so identical
        // private files are always inserted as separate media.
        $inserted = $this->db->selectOne(
            <<<'SQL'
                INSERT INTO platform.media
                    (id, visibility, disk, object_key, original_filename, mime, bytes, width, height, checksum,
                     alt_ar, alt_en, variants_status, variants_queued_at, variants_generated_at, uploaded_by,
                     created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (checksum) WHERE visibility = 'PUBLIC' DO NOTHING
                RETURNING id
                SQL,
            [
                $media->id(), $media->visibility()->value, $media->disk(), $media->objectKey(), $media->originalFilename(),
                $media->mime(), $media->bytes(), $media->width(), $media->height(), $media->checksum(),
                $media->altAr(), $media->altEn(), $media->variantsStatus()?->value, $media->variantsQueuedAt(),
                $media->variantsGeneratedAt(), $media->uploadedBy(), $now, $now,
            ],
            useReadPdo: false,
        );

        return $inserted !== null;
    }

    public function update(Media $media): void
    {
        $this->db->table(self::TABLE)->where('id', $media->id())->update([
            'alt_ar' => $media->altAr(),
            'alt_en' => $media->altEn(),
            'variants_status' => $media->variantsStatus()?->value,
            'variants_queued_at' => $media->variantsQueuedAt(),
            'variants_generated_at' => $media->variantsGeneratedAt(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    public function delete(Media $media): void
    {
        try {
            // A savepoint, so a refused delete does not abort the caller's whole transaction.
            $this->db->transaction(fn () => $this->db->table(self::TABLE)->where('id', $media->id())->delete());
        } catch (QueryException $error) {
            if ($error->getCode() === self::FOREIGN_KEY_VIOLATION) {
                throw new MediaInUse($media->id());
            }

            throw $error;
        }
    }

    private function toMedia(stdClass $row): Media
    {
        return Media::reconstitute(
            (string) $row->id,
            MediaVisibility::from((string) $row->visibility),
            (string) $row->disk,
            (string) $row->object_key,
            (string) $row->original_filename,
            (string) $row->mime,
            (int) $row->bytes,
            $row->width === null ? null : (int) $row->width,
            $row->height === null ? null : (int) $row->height,
            (string) $row->checksum,
            $row->alt_ar === null ? null : (string) $row->alt_ar,
            $row->alt_en === null ? null : (string) $row->alt_en,
            $row->variants_status === null ? null : MediaVariantsStatus::from((string) $row->variants_status),
            $row->variants_queued_at === null ? null : CarbonImmutable::parse((string) $row->variants_queued_at),
            $row->variants_generated_at === null ? null : CarbonImmutable::parse((string) $row->variants_generated_at),
            $row->uploaded_by === null ? null : (string) $row->uploaded_by,
        );
    }
}
