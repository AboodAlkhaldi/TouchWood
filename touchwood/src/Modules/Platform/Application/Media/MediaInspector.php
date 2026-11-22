<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

interface MediaInspector
{
    /**
     * Detects the type from the file's contents (never its name), measures an image, and hashes
     * the bytes with SHA-256.
     */
    public function inspect(string $path): InspectedFile;
}
