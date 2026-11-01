<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use Modules\Payments\Domain\Model\GatewayCall;

interface GatewayCalls
{
    public function record(GatewayCall $call): void;
}
