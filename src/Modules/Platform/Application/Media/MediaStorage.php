<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

use Carbon\CarbonImmutable;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;

/**
 * Object storage for media. Which disk and CDN hold what is configuration, so the hosting
 * provider can be chosen later without code changes.
 *
 * Every original — public or private — is kept on the private disk, because an uploaded photo
 * carries metadata such as its GPS location. Only the variants of public images, which carry no
 * metadata, go on the public disk behind the CDN (owner's decision, 2026-09-16).
 */
interface MediaStorage
{
    /**
     * The private disk that keeps every original.
     */
    public function originalsDisk(): string;

    public function putOriginal(Media $media, string $path): void;

    public function readOriginal(Media $media): string;

    public function putVariant(Media $media, MediaSize $size, ImageFormat $format, string $contents): void;

    /**
     * Removes the original and every variant straight away.
     */
    public function deleteNow(Media $media): void;

    /**
     * Removes the original and every variant once the current transaction commits.
     */
    public function deleteAfterCommit(Media $media): void;

    /**
     * Removes the original and every variant if the current transaction rolls back — including
     * an outer transaction that rolls back after this one committed.
     */
    public function deleteAfterRollBack(Media $media): void;

    public function variantUrl(Media $media, MediaSize $size, ImageFormat $format): string;

    /**
     * An expiring link to a private original, downloaded under its original file name.
     */
    public function temporaryOriginalUrl(Media $media, CarbonImmutable $expiresAt): string;
}
