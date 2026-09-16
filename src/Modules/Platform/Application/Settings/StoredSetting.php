<?php

namespace Modules\Platform\Application\Settings;

final readonly class StoredSetting
{
    public function __construct(
        public int $id,
        public mixed $value,
    ) {}
}
