<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

interface MediaVariantsQueue
{
    /**
     * Queues variant generation. Dispatched after the current transaction commits, so the job
     * never runs for a row that does not exist yet (or was rolled back).
     */
    public function generate(string $mediaId): void;
}
