<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Closure;
use Illuminate\Database\QueryException;
use Modules\Content\Domain\Exception\InvalidContentInput;

/**
 * A file or a category checked a moment ago and deleted before the row naming it is written (PR 3 review): the
 * RESTRICT key refuses the row, and the person is told what the check would have said - the file is no image of
 * the library, the category does not exist - rather than seeing a server error. Any other failure is let through.
 */
final class ForeignKeyRefusals
{
    /** @var array<string, array{0: string, 1: string}> constraint => [box, rule] */
    private const array REFUSALS = [
        'hsm_media' => ['logos', InvalidContentInput::IMAGE_FILE],
        'bi_media' => ['images', InvalidContentInput::IMAGE_FILE],
        'banners_category' => ['category', InvalidContentInput::CATEGORY],
        'popups_media' => ['image', InvalidContentInput::IMAGE_FILE],
        'smart_bars_category' => ['category', InvalidContentInput::CATEGORY],
        'pages_share_ar' => ['share_image_ar', InvalidContentInput::IMAGE_FILE],
        'pages_share_en' => ['share_image_en', InvalidContentInput::IMAGE_FILE],
        'blog_posts_share_ar' => ['share_image_ar', InvalidContentInput::IMAGE_FILE],
        'blog_posts_share_en' => ['share_image_en', InvalidContentInput::IMAGE_FILE],
        'blog_posts_cover' => ['cover', InvalidContentInput::IMAGE_FILE],
        'blog_posts_category' => ['category', InvalidContentInput::CATEGORY],
        'projects_share_ar' => ['share_image_ar', InvalidContentInput::IMAGE_FILE],
        'projects_share_en' => ['share_image_en', InvalidContentInput::IMAGE_FILE],
        'pi_media' => ['images', InvalidContentInput::IMAGE_FILE],
        'poi_media' => ['images', InvalidContentInput::IMAGE_FILE],
        'pri_media' => ['images', InvalidContentInput::IMAGE_FILE],
        'pp_media' => ['photos', InvalidContentInput::IMAGE_FILE],
    ];

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws InvalidContentInput
     */
    public static function guard(Closure $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $failed) {
            $refusal = self::refusal($failed);

            throw $refusal ?? $failed;
        }
    }

    private static function refusal(QueryException $failed): ?InvalidContentInput
    {
        if ((string) $failed->getCode() !== '23503' || preg_match('/foreign key constraint "([a-z_]+)"/', $failed->getMessage(), $found) !== 1) {
            return null;
        }

        $refusal = self::REFUSALS[$found[1]] ?? null;

        return $refusal === null ? null : new InvalidContentInput($refusal[0], $refusal[1]);
    }
}
