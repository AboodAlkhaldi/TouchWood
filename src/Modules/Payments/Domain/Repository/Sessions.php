<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use Modules\Payments\Domain\Model\GatewaySession;

interface Sessions
{
    public function add(GatewaySession $session): void;
}
