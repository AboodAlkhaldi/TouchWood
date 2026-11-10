<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One pop-up on W6's list (content.md §4.4): its name, state and dates. */
#[TypeScript]
final class PopupRowData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $state,
        public ?string $startsAt,
        public ?string $endsAt,
        public int $version,
    ) {}

    /**
     * @param  array<string, mixed>  $row  ScreenReads::popups()
     */
    public static function fromRow(array $row): self
    {
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return new self((string) $text($row['id'] ?? null), (string) $text($row['name'] ?? null), (string) $text($row['state'] ?? null), $text($row['startsAt'] ?? null), $text($row['endsAt'] ?? null), (int) ($row['version'] ?? 0));
    }
}
