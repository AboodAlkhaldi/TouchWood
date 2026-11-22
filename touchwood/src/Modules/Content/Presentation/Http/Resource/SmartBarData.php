<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One smart bar on W2's list (content.md §1.9): its message, where it shows, its state and its dates. */
#[TypeScript]
final class SmartBarData extends Data
{
    public function __construct(
        public string $id,
        /** `CATEGORY` or `CAMPAIGN`. */
        public string $scope,
        public ?string $categoryId,
        public ?string $runId,
        public string $messageAr,
        public string $messageEn,
        public ?string $link,
        public string $state,
        public ?string $startsAt,
        public ?string $endsAt,
    ) {}

    /**
     * @param  array<string, mixed>  $row  ScreenReads::smartBars()
     */
    public static function fromRow(array $row): self
    {
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return new self(
            (string) $text($row['id'] ?? null),
            (string) $text($row['scope'] ?? null),
            $text($row['categoryId'] ?? null),
            $text($row['runId'] ?? null),
            (string) $text($row['messageAr'] ?? null),
            (string) $text($row['messageEn'] ?? null),
            $text($row['link'] ?? null),
            (string) $text($row['state'] ?? null),
            $text($row['startsAt'] ?? null),
            $text($row['endsAt'] ?? null),
        );
    }
}
