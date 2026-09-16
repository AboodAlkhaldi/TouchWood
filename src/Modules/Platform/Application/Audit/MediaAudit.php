<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Audit;

use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
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
    public static function variantsRetried(Media $media, ?MediaVariantsStatus $before): AuditEntryDto
    {
        return new AuditEntryDto('platform.media.variants_retried', self::SUBJECT, $media->id(), null, AuditChanges::none()
            ->changed('variants_status', $before?->value, $media->variantsStatus()?->value));
    }

    public static function deleted(Media $media): AuditEntryDto
    {
        return new AuditEntryDto('platform.media.deleted', self::SUBJECT, $media->id(), null, AuditChanges::none()
            ->changed('checksum', $media->checksum(), null));
    }
}
