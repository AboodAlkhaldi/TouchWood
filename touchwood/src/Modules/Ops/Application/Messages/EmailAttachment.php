<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/** A file an email carries (ops.md §1.11: the daily report's PDF): its name and its bytes. */
final readonly class EmailAttachment
{
    public function __construct(
        public string $name,
        public string $content,
    ) {}
}
