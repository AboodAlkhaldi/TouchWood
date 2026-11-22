<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\MediaVariantsStatus;
use Modules\Platform\Public\Enums\MediaVisibility;

/**
 * The images Content's items name (content.md §1.1 rule 10): **public files of Platform's library, JPEG, PNG or
 * WebP**, uploaded under the item's own permission; and **not ready until Platform has made their sizes**, so
 * Publish, Schedule and Show are refused while any image they would show is pending or failed, naming its box.
 * Each question is one read for every image at once (`mediaOf`). A box among several alike is written `logo#3`.
 */
final readonly class ContentImages
{
    /** @var list<string> */
    private const array TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private PlatformApi $platform,
    ) {}

    /**
     * Each id, lower-cased, refused unless it names a public image of the three types.
     *
     * @param  array<string, string|null>  $images  box => media id (null: none)
     * @return array<string, string|null>
     *
     * @throws InvalidContentInput
     */
    public function files(array $images): array
    {
        $images = array_map(static fn (?string $id): ?string => $id === null || trim($id) === '' ? null : strtolower(trim($id)), $images);
        $named = array_values(array_unique(array_filter($images, static fn (?string $id): bool => $id !== null)));
        $found = $named === [] ? [] : $this->platform->mediaOf($named);

        foreach ($images as $box => $id) {
            $media = $id === null ? null : ($found[$id] ?? null);

            if ($id !== null && ($media === null || $media->visibility !== MediaVisibility::Public || ! in_array($media->mime, self::TYPES, true))) {
                throw new InvalidContentInput(self::box($box), InvalidContentInput::IMAGE_FILE);
            }
        }

        return $images;
    }

    /**
     * Refuses the first image whose sizes are not made yet, or failed (`ImageNotReady`), naming its box.
     *
     * @param  array<string, string|null>  $images  box => media id (null: none)
     *
     * @throws ImageNotReady
     */
    public function requireReady(array $images): void
    {
        $named = array_values(array_unique(array_filter($images, static fn (?string $id): bool => $id !== null)));

        if ($named === []) {
            return;
        }

        $found = $this->platform->mediaOf($named);

        foreach ($images as $box => $id) {
            if ($id === null) {
                continue;
            }

            $status = ($found[strtolower($id)] ?? null)?->variantsStatus;

            if ($status !== MediaVariantsStatus::Ready) {
                throw new ImageNotReady(self::box($box), $status === MediaVariantsStatus::Failed);
            }
        }
    }

    /** A box's name: one of several alike (`logo#3`, `photo#12`) is named as the box they share. */
    private static function box(string $box): string
    {
        return explode('#', $box, 2)[0];
    }
}
