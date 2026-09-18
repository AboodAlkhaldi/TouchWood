<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use LogicException;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Infrastructure\HttpRequestState;
use Modules\Platform\Infrastructure\Queue\JobActorState;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Enums\AuditSource;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Infrastructure\Http\AssignCorrelationId;

/**
 * Both dates of a normal entry come from PostgreSQL's clock (the column defaults), and a CHECK
 * requires them to be equal unless the entry is an import — so nothing can back-date an entry,
 * and an imported one still shows when it was really written (owner's decision, 2026-09-18).
 */
final readonly class DatabaseAuditLog implements AuditLog
{
    public function __construct(
        private ConnectionInterface $db,
        private ActorContext $actors,
        private Container $container,
        private JobActorState $jobs,
        private HttpRequestState $http,
    ) {}

    public function record(AuditEntryDto $entry): void
    {
        $this->requireTransaction($entry);

        $actor = $this->actors->current();
        $source = $this->source($actor);

        $this->db->table('platform.audit_entries')->insert([
            ...$this->row($entry, $actor, $source),
            // Staff actions only (Platform spec §1.5). Customers' and the system's are never kept.
            'ip_address' => $actor->type === ActorType::Staff && $source === AuditSource::Web ? $this->requestIp() : null,
        ]);
    }

    public function recordImported(AuditEntryDto $entry, Actor $actor, DateTimeImmutable $occurredAt): void
    {
        $this->requireTransaction($entry);

        if ($this->actors->current()->type !== ActorType::System) {
            throw new LogicException('Only the system imports history.');
        }

        if ($occurredAt > new DateTimeImmutable) {
            throw new InvalidArgumentException('An imported audit entry cannot happen in the future.');
        }

        $this->db->table('platform.audit_entries')->insert([
            ...$this->row($entry, $actor, AuditSource::Import),
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditEntryDto $entry, Actor $actor, AuditSource $source): array
    {
        $correlationId = Context::get(AssignCorrelationId::CONTEXT_KEY);

        return [
            'source' => $source->value,
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
        ];
    }

    /**
     * A queued job first (it may run inside a request with the "sync" queue), then a request —
     * made by an integration or by a person — and otherwise an artisan command.
     */
    private function source(Actor $actor): AuditSource
    {
        return match (true) {
            $this->jobs->current() !== null => AuditSource::Job,
            $this->http->isHandling() => $actor->type === ActorType::Integration ? AuditSource::Integration : AuditSource::Web,
            default => AuditSource::Console,
        };
    }

    private function requireTransaction(AuditEntryDto $entry): void
    {
        // Outside a transaction the change and its entry could be committed apart, which breaks
        // "no audited change without its entry" (Platform spec §1.5).
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("Audit entry \"{$entry->action}\" must be recorded inside the transaction of the change it records.");
        }
    }

    private function requestIp(): ?string
    {
        return $this->container->bound('request') ? $this->container->make(Request::class)->ip() : null;
    }
}
