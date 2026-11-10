<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The store's announcement bar as its form shows it (content.md §1.5, §4.4 W2); moments in UTC, shown in the store's zone. */
#[TypeScript]
final class BarData extends Data
{
    public function __construct(
        public ?string $textAr,
        public ?string $textEn,
        public ?string $link,
        public bool $isOn,
        public ?string $startsAt,
        public ?string $endsAt,
        public int $version,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  BarsHandler::bar()
     */
    public static function fromFields(array $fields): self
    {
        return new self(
            self::text($fields['text_ar'] ?? null),
            self::text($fields['text_en'] ?? null),
            self::text($fields['link'] ?? null),
            (bool) ($fields['is_on'] ?? false),
            self::text($fields['starts_at'] ?? null),
            self::text($fields['ends_at'] ?? null),
            (int) ($fields['version'] ?? 0),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
