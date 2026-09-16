<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\External;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Domain\Model\Media;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Media on Laravel disks. config/platform.php names the two disks; the provider (S3-compatible
 * storage, CDN URL) is configured there and in .env, never in code.
 */
final readonly class LaravelMediaStorage implements MediaStorage
{
    /**
     * @param  array{public_disk: string, private_disk: string}  $config
     */
    public function __construct(
        private Filesystems $filesystems,
        private LoggerInterface $logger,
        private array $config,
    ) {
        // Originals and private documents must never land where the CDN serves files.
        if ($config['public_disk'] === $config['private_disk']) {
            throw new InvalidArgumentException("MEDIA_PUBLIC_DISK and MEDIA_PRIVATE_DISK must be different disks; both are \"{$config['public_disk']}\".");
        }
    }

    public function originalsDisk(): string
    {
        return $this->config['private_disk'];
    }

    public function putOriginal(Media $media, string $path): void
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("The uploaded file \"{$path}\" cannot be read.");
        }

        try {
            $this->write($this->disk($media->disk()), $media->disk(), $media->objectKey(), $stream, 'private');
        } finally {
            fclose($stream);
        }
    }

    public function readOriginal(Media $media): string
    {
        return (string) $this->disk($media->disk())->get($media->objectKey());
    }

    public function putVariant(Media $media, MediaSize $size, ImageFormat $format, string $contents): void
    {
        $this->write($this->variantsDisk(), $this->config['public_disk'], $media->variantKey($size, $format), $contents, 'public');
    }

    public function deleteNow(Media $media): void
    {
        $this->delete($this->disk($media->disk()), $media->disk(), [$media->objectKey()]);

        if ($media->hasVariants()) {
            $this->delete($this->variantsDisk(), $this->config['public_disk'], $this->variantKeys($media));
        }
    }

    public function deleteAfterCommit(Media $media): void
    {
        DB::afterCommit(fn () => $this->deleteNow($media));
    }

    public function deleteAfterRollBack(Media $media): void
    {
        DB::afterRollBack(fn () => $this->deleteNow($media));
    }

    public function variantUrl(Media $media, MediaSize $size, ImageFormat $format): string
    {
        return $this->urls($this->variantsDisk(), $this->config['public_disk'])->url($media->variantKey($size, $format));
    }

    public function temporaryOriginalUrl(Media $media, CarbonImmutable $expiresAt): string
    {
        return $this->urls($this->disk($media->disk()), $media->disk())->temporaryUrl($media->objectKey(), $expiresAt, [
            // Honoured by S3-compatible storage: download, never render in the storage's origin.
            'ResponseContentDisposition' => self::attachment($media->originalFilename()),
        ]);
    }

    /**
     * @param  string|resource  $contents
     */
    private function write(Filesystem $disk, string $diskName, string $key, mixed $contents, string $visibility): void
    {
        if (! $disk->put($key, $contents, ['visibility' => $visibility])) {
            throw new RuntimeException("Could not write \"{$key}\" to the {$diskName} disk.");
        }
    }

    /**
     * A failed delete only leaves an unreachable file behind, so it is logged, not thrown.
     *
     * @param  list<string>  $keys
     */
    private function delete(Filesystem $disk, string $diskName, array $keys): void
    {
        if (! $disk->delete($keys)) {
            $this->logger->warning('Media files could not be deleted.', ['disk' => $diskName, 'keys' => $keys]);
        }
    }

    /**
     * @return list<string>
     */
    private function variantKeys(Media $media): array
    {
        $keys = [];

        foreach (MediaSize::cases() as $size) {
            foreach (ImageFormat::cases() as $format) {
                $keys[] = $media->variantKey($size, $format);
            }
        }

        return $keys;
    }

    private function variantsDisk(): Filesystem
    {
        return $this->disk($this->config['public_disk']);
    }

    private function disk(string $name): Filesystem
    {
        return $this->filesystems->disk($name);
    }

    private function urls(Filesystem $disk, string $name): FilesystemAdapter
    {
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException("The {$name} disk cannot build URLs.");
        }

        return $disk;
    }

    /**
     * RFC 6266: a plain ASCII fallback for old clients, and the exact UTF-8 name (Arabic included).
     */
    private static function attachment(string $filename): string
    {
        // Character by character (/u): an Arabic letter becomes one underscore, not two.
        $fallback = (string) preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename);

        return "attachment; filename=\"{$fallback}\"; filename*=UTF-8''".rawurlencode($filename);
    }
}
