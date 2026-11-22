<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\SmartBar;

/** Smart bars (content.md §1.9, §5: `content.smart_bars`). */
interface SmartBars
{
    public function find(string $id): ?SmartBar;

    public function lock(string $id): ?SmartBar;

    public function add(SmartBar $bar, ?string $by): void;

    public function update(SmartBar $bar, ?string $by): void;

    public function delete(string $id): void;
}
