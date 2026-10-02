<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One answer an application gave to a request of the rejection before it (b2b.md §1.2), shown with
 * the request's own label: a text, or a file opened like a paper.
 */
#[TypeScript]
final class StaffAnswerData extends Data
{
    public function __construct(
        public string $requestId,
        /** The staff-written label of the request it answers; null if that request is not found. */
        public ?string $label,
        public ?string $text,
        public ?string $mediaId,
        /** The file's name, for a file answer. */
        public ?string $fileName,
    ) {}
}
