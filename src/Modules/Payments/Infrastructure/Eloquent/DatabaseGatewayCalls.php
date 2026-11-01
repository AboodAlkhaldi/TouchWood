<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Model\GatewayCall;
use Modules\Payments\Domain\Repository\GatewayCalls;

final readonly class DatabaseGatewayCalls implements GatewayCalls
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function record(GatewayCall $call): void
    {
        $this->db->table('payments.calls')->insert([
            'attempt_id' => $call->attemptId,
            'store_id' => $call->storeId,
            'endpoint' => $call->endpoint,
            'request' => $call->request === null ? null : json_encode($call->request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'http_status' => $call->httpStatus,
            'response' => $call->response === null ? null : json_encode($call->response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'error' => $call->error,
            'took_ms' => $call->tookMs,
            'at' => CarbonImmutable::now(),
        ]);
    }
}
