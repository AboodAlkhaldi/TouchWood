<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\RemoveSubscriber;

/** Staff take a guest off a store's newsletter list (ops.md §3: "remove a guest"). */
final readonly class RemoveSubscriber
{
    public function __construct(
        public string $subscriberId,
    ) {}
}
