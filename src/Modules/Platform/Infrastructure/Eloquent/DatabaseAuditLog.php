<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use LogicException;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Infrastructure\Http\AssignCorrelationId;

final readonly class DatabaseAuditLog implements AuditLog
{
    public function __construct(
        private ConnectionInterface $db,
        private ActorContext $actors,
        private Container $container,
    ) {}

    public function record(AuditEntryDto $entry): void
    {
        // Outside a transaction the change and its entry could be committed apart, which breaks
        // "no audited change without its entry" (Platform spec §1.5).
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("Audit entry \"{$entry->action}\" must be recorded inside the transaction of the change it records.");
        }

        $actor = $this->actors->current();
        $correlationId = Context::get(AssignCorrelationId::CONTEXT_KEY);

        $this->db->table('platform.audit_entries')->insert([
            'occurred_at' => CarbonImmutable::now(),
            'store_id' => $entry->storeId,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            // A queued job acts as the system; this is whose action queued it.
            'requested_by_type' => $actor->requestedBy?->type->value,
            'requested_by_id' => $actor->requestedBy?->id,
            'action' => $entry->action,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'changes' => json_encode((object) $entry->changes->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'correlation_id' => is_string($correlationId) ? $correlationId : null,
            // Staff actions only (Platform spec §1.5). Customers' and the system's are never kept.
            'ip_address' => $actor->type === ActorType::Staff ? $this->requestIp() : null,
        ]);
    }

    private function requestIp(): ?string
    {
        return $this->container->bound('request') ? $this->container->make(Request::class)->ip() : null;
    }
}
