<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use Modules\Ops\Domain\Model\SentMessage;

/** The record of every email and SMS (ops.md §5: `ops.sent_messages`) - never a code or a link. */
interface SentMessages
{
    public function record(SentMessage $message): void;
}
