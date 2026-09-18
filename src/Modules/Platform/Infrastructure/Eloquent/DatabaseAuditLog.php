<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use DateTimeImmutable;
use DateTimeZone;
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
use Shared\Application\CorrelationId;

/**
 * Both dates of a normal entry come from PostgreSQL's clock (the column defaults), and a CHECK
 * requires them to be equal unless the entry is an import — so nothing can back-date an entry,
 * and an imported one still shows when it was really written (owner's decision, 2026-09-18).
 *
 * Every rule the table's constraints enforce is checked here first, so a mistake is refused with
 * a clear message instead of a database error (owner's rule, 2026-09-18).
 */
final readonly class DatabaseAuditLog implements AuditLog
{
    /** The lengths of platform.audit_entries' columns. */
    private const int MAX_ACTION = 100;

    private const int MAX_SUBJECT_TYPE = 100;

    private const int MAX_SUBJECT_ID = 64;

    /** "{module}.{resource}.{what happened}", as AuditEntryDto describes it. */
    private const string ACTION = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}\z/';

    /** "{module}.{resource}". */
    private const string SUBJECT_TYPE = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/';

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
        $this->requireValid($entry);

        // Inside a queued job, the job's own actor — the system on behalf of whoever queued it —
        // whatever ActorContext binding is in place.
        $jobActor = $this->jobs->current();
        $actor = $jobActor ?? $this->actors->current();
        $source = $jobActor !== null ? AuditSource::Job : $this->requestSource($actor);

        if ($jobActor === null && $actor->requestedBy !== null) {
            throw new LogicException("Audit entry \"{$entry->action}\": only a queued job acts on someone's behalf, but this actor carries a requester outside a job.");
        }

        $this->db->table('platform.audit_entries')->insert([
            ...$this->row($entry, $actor, $source),
            // Staff actions only (Platform spec §1.5). Customers' and the system's are never kept.
            'ip_address' => $actor->type === ActorType::Staff && $source === AuditSource::Web ? $this->requestIp() : null,
        ]);
    }

    public function recordImported(AuditEntryDto $entry, Actor $actor, DateTimeImmutable $occurredAt): void
    {
        $this->requireTransaction($entry);
        $this->requireValid($entry);

        if ($this->actors->current()->type !== ActorType::System) {
            throw new LogicException('Only the system imports history.');
        }

        if ($actor->requestedBy !== null) {
            throw new InvalidArgumentException('Imported history names the actor who acted at the time; it has no requester.');
        }

        // Written in UTC with its offset: the database would read a date written without one as
        // UTC, moving an entry dated 10:00+03:00 three hours later. (The column keeps whole seconds.)
        $occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));

        // recorded_at is the transaction's start time, earlier than PHP's clock: compare with it.
        if ($occurredAt >= $this->transactionStartedAt()) {
            throw new InvalidArgumentException('An imported audit entry must be dated before it is recorded: it cannot happen in the future.');
        }

        $this->db->table('platform.audit_entries')->insert([
            ...$this->row($entry, $actor, AuditSource::Import),
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditEntryDto $entry, Actor $actor, AuditSource $source): array
    {
        $correlationId = Context::get(CorrelationId::CONTEXT_KEY);

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
            'changes' => $this->changesJson($entry),
            'correlation_id' => is_string($correlationId) ? $correlationId : null,
        ];
    }

    /**
     * Outside a queued job: a request — made by an integration or by a person — and otherwise an
     * artisan command.
     */
    private function requestSource(Actor $actor): AuditSource
    {
        if (! $this->http->isHandling()) {
            return AuditSource::Console;
        }

        return $actor->type === ActorType::Integration ? AuditSource::Integration : AuditSource::Web;
    }

    private function requireValid(AuditEntryDto $entry): void
    {
        $lengths = [
            'action' => [$entry->action, self::MAX_ACTION],
            'subject type' => [$entry->subjectType, self::MAX_SUBJECT_TYPE],
            'subject id' => [$entry->subjectId, self::MAX_SUBJECT_ID],
        ];

        foreach ($lengths as $name => [$value, $max]) {
            if ($value === '' || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
                throw new InvalidArgumentException("An audit entry's {$name} must be 1 to {$max} characters of UTF-8 text, \"{$value}\" is not.");
            }
        }

        if (preg_match(self::ACTION, $entry->action) !== 1 || preg_match(self::SUBJECT_TYPE, $entry->subjectType) !== 1) {
            throw new InvalidArgumentException("An audit entry's action must look like \"module.resource.updated\" and its subject type like \"module.resource\"; got \"{$entry->action}\" and \"{$entry->subjectType}\".");
        }

        // PostgreSQL refuses a NUL character in text, and a line break has no place in an id.
        if (preg_match('/\p{Cc}/u', $entry->subjectId) === 1) {
            throw new InvalidArgumentException("An audit entry's subject id must not contain control characters.");
        }

        // Read inside the transaction, not from the store cache: a store created in this same
        // transaction is audited before the cache knows it.
        if ($entry->storeId !== null && ! $this->db->table('platform.stores')->where('id', $entry->storeId)->exists()) {
            throw new InvalidArgumentException("Audit entry \"{$entry->action}\" names a store that does not exist: \"{$entry->storeId}\".");
        }
    }

    private function changesJson(AuditEntryDto $entry): string
    {
        $json = json_encode((object) $entry->changes->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        // jsonb refuses the NUL character, even escaped.
        if (str_contains($json, '\u0000')) {
            throw new InvalidArgumentException("Audit entry \"{$entry->action}\": a changed value contains a NUL character.");
        }

        return $json;
    }

    private function requireTransaction(AuditEntryDto $entry): void
    {
        // Outside a transaction the change and its entry could be committed apart, which breaks
        // "no audited change without its entry" (Platform spec §1.5).
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("Audit entry \"{$entry->action}\" must be recorded inside the transaction of the change it records.");
        }
    }

    private function transactionStartedAt(): DateTimeImmutable
    {
        $row = $this->db->selectOne("select to_char(now() at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') as started_at");

        if (! is_object($row) || ! is_string($row->started_at ?? null)) {
            throw new LogicException('PostgreSQL did not return the transaction time.');
        }

        return new DateTimeImmutable($row->started_at);
    }

    private function requestIp(): ?string
    {
        return $this->container->bound('request') ? $this->container->make(Request::class)->ip() : null;
    }
}
