<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One banner on W3's list (content.md §4.4): its thumbnail, name, where it shows, state, dates and place in the order. */
#[TypeScript]
final class BannerRowData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $categoryId,
        public string $state,
        public ?string $startsAt,
        public ?string $endsAt,
        public int $position,
        public ?string $thumbnail,
    ) {}

    /**
     * @param  array<string, mixed>  $row  ScreenReads::banners()
     */
    public static function fromRow(array $row, ?string $thumbnail): self
    {
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return new self((string) $text($row['id'] ?? null), (string) $text($row['name'] ?? null), $text($row['categoryId'] ?? null), (string) $text($row['state'] ?? null), $text($row['startsAt'] ?? null), $text($row['endsAt'] ?? null), (int) ($row['position'] ?? 0), $thumbnail);
    }
}
