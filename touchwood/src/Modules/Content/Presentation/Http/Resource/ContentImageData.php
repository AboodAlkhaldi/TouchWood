<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An image an item names, as its upload box shows it (content.md §4.2): the file's name, its size in pixels for the size
 * note (§1.6), whether its sizes are made (`PENDING`, `READY`, `FAILED`; §1.1 rule 10), and a small picture once they
 * are - never the original, which is never served.
 */
#[TypeScript]
final class ContentImageData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?int $width,
        public ?int $height,
        public string $status,
        public ?string $thumbnail,
    ) {}
}
