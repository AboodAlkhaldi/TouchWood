<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Audit;

use DateTimeImmutable;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\MediaUseDto;
use Modules\Platform\Public\Enums\MediaVariantsStatus;

/**
 * Audit entries for media. Media is global, so entries have no store. The original file name
 * is recorded only as "changed": an uploaded document's name can carry personal data.
 */
final class MediaAudit
{
    private const string SUBJECT = 'platform.media';

    public static function uploaded(Media $media): AuditEntryDto
    {
        return new AuditEntryDto('platform.media.uploaded', self::SUBJECT, $media->id(), null, AuditChanges::none()
            ->changed('visibility', null, $media->visibility()->value)
            ->changed('mime', null, $media->mime())
            ->changed('bytes', null, $media->bytes())
            ->changed('checksum', null, $media->checksum())
            ->personal('original_filename'));
    }

    /**
     * @param  array{alt_ar: string|null, alt_en: string|null}  $before
     * @param  list<string>  $changed
     */
    public static function altTextChanged(Media $media, array $before, array $changed): AuditEntryDto
    {
        $after = ['alt_ar' => $media->altAr(), 'alt_en' => $media->altEn()];
        $changes = AuditChanges::none();

        foreach ($changed as $attribute) {
            if (array_key_exists($attribute, $after)) {
                $changes->changed($attribute, $before[$attribute], $after[$attribute]);
            }
        }

        return new AuditEntryDto('platform.media.alt_text_changed', self::SUBJECT, $media->id(), null, $changes);
    }

    /**
     * A staff member asked for the variants again. The system's own sweep of stuck images is not
     * audited: nobody acted.
     */
    public static function variantsRetried(Media $media, ?MediaVariantsStatus $before, ?DateTimeImmutable $queuedBefore): AuditEntryDto
    {
        return new AuditEntryDto('platform.media.variants_retried', self::SUBJECT, $media->id(), null, AuditChanges::none()
            ->changed('variants_status', $before?->value, $media->variantsStatus()?->value)
            ->changed('variants_queued_at', $queuedBefore?->format(DATE_ATOM), $media->variantsQueuedAt()?->format(DATE_ATOM)));
    }

    /**
     * @param  list<MediaUseDto>  $detachedFrom  where the media was used until this delete
     */
    public static function deleted(Media $media, array $detachedFrom = []): AuditEntryDto
    {
        $changes = AuditChanges::none()->changed('checksum', $media->checksum(), null);

        if ($detachedFrom !== []) {
            $changes->changed('detached_from', array_map(fn (MediaUseDto $use): string => $use->describe(), $detachedFrom), null);
        }

        return new AuditEntryDto('platform.media.deleted', self::SUBJECT, $media->id(), null, $changes);
    }
}
