<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Inbox;

use DateTimeImmutable;
use Modules\Ops\Public\Enums\MessageKind;

/** One entry of a bell, in the reader's language (ops.md §1.2). */
final readonly class InboxRow
{
    public function __construct(
        public string $id,
        public MessageKind $kind,
        public string $storeId,
        public string $title,
        public string $line,
        public ?string $link,
        public bool $read,
        public DateTimeImmutable $createdAt,
    ) {}
}
