<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One line of a checked sheet (content.md §1.15, W11b): its row, its two addresses, what Apply would do, and why not. */
#[TypeScript]
final class SheetLineData extends Data
{
    public function __construct(
        public int $line,
        public string $old,
        public string $new,
        /** `NEW`, `SAME`, `CHANGE` or `REFUSED`. */
        public string $result,
        /** The refusal's rule (`content::errors.redirect_invalid.rules.*`), or null. */
        public ?string $reason,
    ) {}

    /**
     * @param  array{line: int, old: string, new: string, result: string, reason: string|null}  $line
     */
    public static function fromLine(array $line): self
    {
        return new self($line['line'], $line['old'], $line['new'], $line['result'], $line['reason']);
    }
}
