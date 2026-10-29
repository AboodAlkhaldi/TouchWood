<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Modules\Ops\Public\Enums\MessageKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry of a bell (ops.md §1.2, §4): its kind, the store it is about (a staff bell spans stores),
 * the title and line in the reader's language, the link to open, whether it was read, and when it
 * came (ISO 8601, UTC).
 */
#[TypeScript]
final class InboxEntryData extends Data
{
    public function __construct(
        public string $id,
        public MessageKind $kind,
        public string $storeCode,
        public string $storeName,
        public string $title,
        public string $line,
        public ?string $link,
        public bool $read,
        public string $createdAt,
    ) {}
}
