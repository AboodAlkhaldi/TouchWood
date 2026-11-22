<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Modules\Platform\Application\Media\MediaVariantsQueue;

final class LaravelMediaVariantsQueue implements MediaVariantsQueue
{
    public function generate(string $mediaId): void
    {
        GenerateMediaVariantsJob::dispatch($mediaId)->afterCommit();
    }
}
