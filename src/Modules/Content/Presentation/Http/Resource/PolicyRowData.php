<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One policy on Content › Policies (flags-2026-11.md S3.7): Title · Kind · Stores · Version · Changed · By - its titles
 * per language, its kind (`PolicyKind`'s value), the names of the stores it serves in the page's language, the number of
 * its published text (0 before its first publish, F14), when it was last changed and by whom.
 */
#[TypeScript]
final class PolicyRowData extends Data
{
    /**
     * @param  list<string>  $stores  the stores' names
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $status,
        public string $titleAr,
        public ?string $titleEn,
        public array $stores,
        public int $textVersion,
        public ?string $changedAt,
        public ?string $changedBy,
    ) {}

    /**
     * @param  array<string, mixed>  $row  PoliciesHandler::list()'s row
     * @param  array<string, string>  $names  store id => name, in the stores' order
     */
    public static function fromRow(array $row, array $names): self
    {
        $text = static fn (string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;
        // In the stores' own order, as every store list of the panel (`$names` is in it).
        $order = array_flip(array_keys($names));
        $ids = array_map(strval(...), is_array($row['stores'] ?? null) ? array_values($row['stores']) : []);
        usort($ids, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
        $stores = array_map(static fn (string $id): string => $names[$id] ?? $id, $ids);

        return new self((string) $text('id'), (string) $text('kind'), (string) $text('status'), (string) $text('titleAr'), $text('titleEn'), $stores, (int) ($row['textVersion'] ?? 0), $text('changedAt'), $text('changedBy'));
    }
}
