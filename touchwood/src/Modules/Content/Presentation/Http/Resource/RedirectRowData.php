<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of W11 (content.md §1.15): old address, new address, visits, last visit, and what Check Addresses found
 * (`FINE`, `FORWARDS` with its final address, `NOT_FOUND`, `STORE_OFF`, `CANT_BE_REACHED`; null until checked).
 */
#[TypeScript]
final class RedirectRowData extends Data
{
    public function __construct(
        public string $oldPath,
        public string $newPath,
        public int $visits,
        public ?string $lastVisitAt,
        public ?string $check,
        public ?string $finalPath,
    ) {}

    /**
     * @param  array{oldPath: string, newPath: string, visits: int, lastVisitAt: string|null, check: string|null, finalPath: string|null}  $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row['oldPath'], $row['newPath'], $row['visits'], $row['lastVisitAt'], $row['check'], $row['finalPath']);
    }
}
