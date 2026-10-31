<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Alerts;

use Modules\Platform\Public\Dto\StoreDto;

/** What one message is written for (MessageWriter): the event's moment, its store, and its customer when it names one. */
final readonly class MessageTarget
{
    public function __construct(
        public EventMessage $message,
        public StoreDto $store,
        public ?string $customerId,
    ) {}
}
