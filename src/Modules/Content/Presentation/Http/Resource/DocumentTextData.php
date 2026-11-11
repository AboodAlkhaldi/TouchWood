<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A page's, post's or project's words in one language as its form shows them (content.md §1.11-§1.14): the body as the
 * editor's marks (§1.2), each `image:N` of it with its file.
 */
#[TypeScript]
final class DocumentTextData extends Data
{
    /**
     * @param  array<int, string>  $images  ref => media id
     */
    public function __construct(
        public string $title,
        public string $slug,
        public string $body,
        public array $images,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public ?string $summary,
        public ?string $coverAlt,
        public ?string $city,
    ) {}

    /**
     * @param  array<string, mixed>  $text  DocumentsHandler::document()'s `texts.{locale}`
     */
    public static function fromRead(array $text): self
    {
        $words = static fn (string $key): ?string => is_string($text[$key] ?? null) ? $text[$key] : null;
        $images = [];

        foreach (is_array($text['images'] ?? null) ? $text['images'] : [] as $ref => $media) {
            if (is_string($media)) {
                $images[(int) $ref] = $media;
            }
        }

        return new self((string) $words('title'), (string) $words('slug'), (string) $words('body'), $images, $words('seoTitle'), $words('seoDescription'), $words('summary'), $words('coverAlt'), $words('city'));
    }
}
