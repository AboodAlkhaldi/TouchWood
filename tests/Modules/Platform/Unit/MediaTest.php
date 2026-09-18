<?php

declare(strict_types=1);

use Modules\Platform\Domain\Exception\InvalidMediaAttribute;
use Modules\Platform\Domain\Exception\InvalidMediaVariantsTransition;
use Modules\Platform\Domain\Exception\MediaTooLarge;
use Modules\Platform\Domain\Exception\UnsupportedMediaType;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;

const TEST_CHECKSUM = 'a3f1c9d2e8b7a6f5e4d3c2b1a0f9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1';

function mediaForTest(
    MediaVisibility $visibility = MediaVisibility::Public,
    string $mime = 'image/jpeg',
    int $bytes = 1000,
    ?int $width = 1600,
    ?int $height = 900,
    string $filename = 'hinge.jpg',
    bool $animated = false,
    ?DateTimeImmutable $now = null,
): Media {
    return Media::upload('01j8z3k4m5n6p7q8r9s0t1v2w3', $visibility, 'local', $filename, $mime, $bytes, 10_485_760, $width, $height, $animated, TEST_CHECKSUM, null, $now ?? new DateTimeImmutable('2026-09-16 12:00:00'));
}

describe('uploading', function () {
    it('accepts JPEG, PNG and WebP as public images', function (string $mime) {
        expect(mediaForTest(mime: $mime)->mime())->toBe($mime);
    })->with(['image/jpeg', 'image/png', 'image/webp']);

    it('accepts PDF, JPEG and PNG as private documents', function (string $mime) {
        $isImage = $mime !== 'application/pdf';

        expect(mediaForTest(MediaVisibility::Private, $mime, width: $isImage ? 800 : null, height: $isImage ? 600 : null)->mime())->toBe($mime);
    })->with(['application/pdf', 'image/jpeg', 'image/png']);

    it('refuses a type the visibility does not accept', function (MediaVisibility $visibility, string $mime) {
        mediaForTest($visibility, $mime, width: null, height: null);
    })->throws(UnsupportedMediaType::class)->with([
        'a PDF as a public image' => [MediaVisibility::Public, 'application/pdf'],
        'WebP as a private document' => [MediaVisibility::Private, 'image/webp'],
        'SVG, which can carry scripts' => [MediaVisibility::Public, 'image/svg+xml'],
        'a GIF' => [MediaVisibility::Public, 'image/gif'],
    ]);

    it('refuses a file over the upload limit, and accepts one exactly at it', function () {
        expect(Media::upload('01j8z3k4m5n6p7q8r9s0t1v2w3', MediaVisibility::Public, 'local', 'ok.jpg', 'image/jpeg', 10_485_760, 10_485_760, 100, 100, false, TEST_CHECKSUM, null, new DateTimeImmutable)->bytes())->toBe(10_485_760)
            ->and(fn () => Media::upload('01j8z3k4m5n6p7q8r9s0t1v2w3', MediaVisibility::Public, 'local', 'big.jpg', 'image/jpeg', 10_485_761, 10_485_760, 100, 100, false, TEST_CHECKSUM, null, new DateTimeImmutable))->toThrow(MediaTooLarge::class);
    });

    it('refuses a checksum that is not a lowercase SHA-256, before the database would', function (string $checksum) {
        Media::upload('01j8z3k4m5n6p7q8r9s0t1v2w3', MediaVisibility::Public, 'local', 'hinge.jpg', 'image/jpeg', 1000, 10_485_760, 100, 100, false, $checksum, null, new DateTimeImmutable);
    })->throws(InvalidMediaAttribute::class, 'SHA-256')->with([
        'uppercase' => [strtoupper(TEST_CHECKSUM)],
        'too short' => [substr(TEST_CHECKSUM, 0, 63)],
        'not hex' => [str_repeat('g', 64)],
    ]);

    it('refuses an empty file', function () {
        mediaForTest(bytes: 0);
    })->throws(InvalidMediaAttribute::class);

    it('refuses an image it could not measure', function () {
        mediaForTest(width: null, height: null);
    })->throws(UnsupportedMediaType::class);

    it('refuses an image whose pixels would exhaust memory, even when the file is small', function (int $width, int $height) {
        mediaForTest(width: $width, height: $height);
    })->throws(MediaTooLarge::class)->with([
        'a side over 12000 pixels' => [12001, 1],
        'over 50 million pixels' => [7072, 7072],
    ]);

    it('accepts an image exactly at the pixel limits', function (int $width, int $height) {
        expect(mediaForTest(width: $width, height: $height)->width())->toBe($width);
    })->with([
        'a side of exactly 12000 pixels' => [12000, 1],
        'exactly 50 million pixels' => [10000, 5000],
    ]);

    it('refuses an animated image, which cannot be resized', function () {
        mediaForTest(mime: 'image/webp', animated: true);
    })->throws(UnsupportedMediaType::class);

    it('keeps only the base name of the uploaded file', function () {
        expect(mediaForTest(filename: '../../etc/passwd')->originalFilename())->toBe('passwd')
            ->and(mediaForTest(filename: 'C:\\Users\\me\\receipt.jpg')->originalFilename())->toBe('receipt.jpg')
            ->and(mediaForTest(filename: 'سجل تجاري.pdf', mime: 'application/pdf', visibility: MediaVisibility::Private, width: null, height: null)->originalFilename())->toBe('سجل تجاري.pdf');
    });

    it('removes invisible characters that could disguise the extension', function () {
        // U+202E RIGHT-TO-LEFT OVERRIDE would display "invoice\u{202E}gpj.exe" as "invoiceexe.jpg".
        expect(mediaForTest(filename: "invoice\u{202E}gpj.exe")->originalFilename())->toBe('invoicegpj.exe');
    });

    it('refuses a file name or alt text that is not valid UTF-8', function (Closure $make) {
        $make();
    })->throws(InvalidMediaAttribute::class)->with([
        'file name' => [fn () => mediaForTest(filename: "bad\xC3\x28.jpg")],
        'alt text' => [fn () => mediaForTest()->changeAltText("bad\xC3\x28", null)],
    ]);

    it('refuses control characters in alt text, but keeps direction marks Arabic text may need', function () {
        $media = mediaForTest();
        $media->changeAltText("مفصلة\u{200F} 35mm", null);

        expect($media->altAr())->toBe("مفصلة\u{200F} 35mm")
            ->and(fn () => $media->changeAltText("a\0b", null))->toThrow(InvalidMediaAttribute::class)
            ->and(fn () => $media->changeAltText("line\nbreak", null))->toThrow(InvalidMediaAttribute::class);
    });

    it('refuses an empty file name', function () {
        mediaForTest(filename: "\n");
    })->throws(InvalidMediaAttribute::class);

    it('stores public and private files under different folders with the right extension', function () {
        expect(mediaForTest()->objectKey())->toBe('media/01j8z3k4m5n6p7q8r9s0t1v2w3.jpg')
            ->and(mediaForTest(MediaVisibility::Private, 'application/pdf', width: null, height: null)->objectKey())->toBe('private/01j8z3k4m5n6p7q8r9s0t1v2w3.pdf');
    });

    it('generates variants only for public images, queued from the moment of upload', function () {
        $now = new DateTimeImmutable('2026-09-16 12:00:00');

        expect(mediaForTest(now: $now)->variantsStatus())->toBe(MediaVariantsStatus::Pending)
            ->and(mediaForTest(now: $now)->variantsQueuedAt())->toEqual($now)
            ->and(mediaForTest(MediaVisibility::Private, 'image/png')->hasVariants())->toBeFalse()
            ->and(mediaForTest(MediaVisibility::Private, 'image/png')->variantsQueuedAt())->toBeNull()
            ->and(mediaForTest(MediaVisibility::Private, 'application/pdf', width: null, height: null)->hasVariants())->toBeFalse();
    });

    it('derives variant keys from the original key', function () {
        expect(mediaForTest()->variantKey(MediaSize::Card, ImageFormat::Avif))->toBe('media/01j8z3k4m5n6p7q8r9s0t1v2w3/card.avif')
            ->and(mediaForTest()->variantKey(MediaSize::Zoom, ImageFormat::Jpeg))->toBe('media/01j8z3k4m5n6p7q8r9s0t1v2w3/zoom.jpg');
    });
});

describe('variant states', function () {
    it('moves PENDING to READY', function () {
        $media = mediaForTest();
        $media->markVariantsReady(new DateTimeImmutable);

        expect($media->variantsStatus())->toBe(MediaVariantsStatus::Ready)
            ->and($media->variantsGeneratedAt())->not->toBeNull();
    });

    it('moves PENDING to FAILED and back to PENDING on retry, with a new queue time', function () {
        $media = mediaForTest(now: new DateTimeImmutable('2026-09-16 12:00:00'));
        $media->markVariantsFailed();
        $media->pullChanges();

        $media->retryVariants(new DateTimeImmutable('2026-09-16 12:01:00'));

        expect($media->variantsStatus())->toBe(MediaVariantsStatus::Pending)
            ->and($media->variantsQueuedAt())->toEqual(new DateTimeImmutable('2026-09-16 12:01:00'))
            ->and($media->pullChanges())->toBe(['variants_status', 'variants_queued_at']);
    });

    it('queues a PENDING image again only once it has waited 15 minutes', function () {
        $media = mediaForTest(now: new DateTimeImmutable('2026-09-16 12:00:00'));

        expect($media->isStalePending(new DateTimeImmutable('2026-09-16 12:14:59')))->toBeFalse()
            ->and(fn () => $media->retryVariants(new DateTimeImmutable('2026-09-16 12:14:59')))->toThrow(InvalidMediaVariantsTransition::class)
            ->and($media->isStalePending(new DateTimeImmutable('2026-09-16 12:15:00')))->toBeTrue();

        $media->retryVariants(new DateTimeImmutable('2026-09-16 12:15:00'));

        expect($media->variantsStatus())->toBe(MediaVariantsStatus::Pending)
            ->and($media->isStalePending(new DateTimeImmutable('2026-09-16 12:16:00')))->toBeFalse();
    });

    it('never changes a READY image', function (string $transition) {
        $media = mediaForTest(now: new DateTimeImmutable('2026-09-16 12:00:00'));
        $media->markVariantsReady(new DateTimeImmutable);

        match ($transition) {
            'ready' => $media->markVariantsReady(new DateTimeImmutable),
            'failed' => $media->markVariantsFailed(),
            'retry' => $media->retryVariants(new DateTimeImmutable('2026-09-17 12:00:00')),
            default => throw new LogicException("Unknown transition \"{$transition}\"."),
        };
    })->throws(InvalidMediaVariantsTransition::class)->with(['ready', 'failed', 'retry']);

    it('cannot retry a file without variants', function () {
        mediaForTest(MediaVisibility::Private, 'application/pdf', width: null, height: null)->retryVariants(new DateTimeImmutable('2030-01-01'));
    })->throws(InvalidMediaVariantsTransition::class);
});

describe('alt text', function () {
    it('records only real changes, and treats empty text as none', function () {
        $media = mediaForTest();

        $media->changeAltText('مفصلة', '  ');

        expect($media->altAr())->toBe('مفصلة')
            ->and($media->altEn())->toBeNull()
            ->and($media->pullChanges())->toBe(['alt_ar']);
    });

    it('counts characters, not bytes, so 255 Arabic letters fit', function () {
        $media = mediaForTest();
        $media->changeAltText(str_repeat('م', 255), null);

        expect(mb_strlen((string) $media->altAr()))->toBe(255)
            ->and(fn () => $media->changeAltText(str_repeat('م', 256), null))->toThrow(InvalidMediaAttribute::class);
    });

    it('refuses alt text over 255 characters', function () {
        mediaForTest()->changeAltText(str_repeat('a', 256), null);
    })->throws(InvalidMediaAttribute::class);
});

it('limits each size by its longest side, as the owner decided', function (MediaSize $size, int $edge) {
    expect($size->longestEdge())->toBe($edge);
})->with([
    [MediaSize::Thumb, 200],
    [MediaSize::Card, 600],
    [MediaSize::Detail, 1200],
    [MediaSize::Zoom, 2400],
]);
