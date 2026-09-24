<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Application\Query\ListAudit\AuditEntryRow;
use Modules\Platform\Application\Query\ListAudit\ListAudit;
use Modules\Platform\Application\Query\ListAudit\ListAuditHandler;
use Modules\Platform\Application\Query\StoreDirectory;

/**
 * Platform's audit reads, in the shape the screen wants (frontend.md 3.5, E6).
 *
 * It decides nothing: which entries this reader may see is the handler's answer. What happens here
 * is naming - a store by its name rather than its id, an action in words where a module wrote them
 * down - and turning the stored changes into rows a screen can lay out.
 */
final readonly class AuditPages
{
    /** The ways a change can reach the system; the table's own check constraint holds the same list. */
    public const array SOURCES = ['WEB', 'INTEGRATION', 'CONSOLE', 'JOB', 'IMPORT'];

    public function __construct(
        private Application $app,
        private StoreDirectory $directory,
    ) {}

    /**
     * E6.
     *
     * @param  array<string, string|null>  $filters
     */
    public function list(ListAuditHandler $handler, array $filters, ?string $cursorOccurredAt, ?int $cursorId): AuditLogPage
    {
        $page = $handler->handle(new ListAudit(
            $filters['from'] ?? null,
            $filters['until'] ?? null,
            $filters['actor'] ?? null,
            $filters['action'] ?? null,
            $filters['source'] ?? null,
            $cursorOccurredAt,
            $cursorId,
        ));

        $stores = [];

        foreach ($this->directory->stores() as $store) {
            $stores[$store->id] = $store->name->in($this->locale());
        }

        return new AuditLogPage(
            array_map(fn (AuditEntryRow $entry): AuditRow => $this->row($entry, $stores), $page->entries),
            $handler->actions(),
            self::SOURCES,
            $filters,
            $page->nextOccurredAt,
            $page->nextId,
        );
    }

    /**
     * @param  array<string, string>  $stores
     */
    private function row(AuditEntryRow $entry, array $stores): AuditRow
    {
        return new AuditRow(
            $entry->id,
            $entry->occurredAt,
            $entry->action,
            $this->actionLabel($entry->action),
            $entry->subjectType,
            $entry->subjectId,
            $entry->source,
            $entry->actorType,
            $entry->actorId,
            // Left for now: naming an actor means asking Access who a staff id belongs to, and the
            // log records ids precisely so it never depends on a name that may since have changed.
            // The screen shows the id, which is what the entry actually holds.
            null,
            $entry->requestedByType,
            $entry->requestedById,
            $entry->storeId === null ? null : ($stores[$entry->storeId] ?? $entry->storeId),
            $entry->ipAddress,
            $this->changes($entry->changes),
        );
    }

    /**
     * An action in the words of the module that records it: "platform.store.updated" is read from
     * platform::audit.store.updated. An action nobody has written down shows as it is recorded,
     * which is ugly and exact - and never a blank line in a log that is kept forever.
     */
    private function actionLabel(string $action): string
    {
        [$module, $rest] = explode('.', $action, 2) + [1 => ''];
        $key = "{$module}::audit.{$rest}";
        $translated = __($key, [], $this->locale());

        return is_string($translated) && $translated !== $key ? $translated : $action;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return list<AuditChangeRow>
     */
    private function changes(array $changes): array
    {
        $rows = [];

        foreach ($changes as $attribute => $change) {
            // "changed" on its own is a personal field: recorded as having changed, never with its
            // values (platform.md 1.5). Anything else is a [from, to] pair.
            if (! is_array($change)) {
                $rows[] = new AuditChangeRow((string) $attribute, null, null, true);

                continue;
            }

            $rows[] = new AuditChangeRow(
                (string) $attribute,
                self::text($change[0] ?? null),
                self::text($change[1] ?? null),
                false,
            );
        }

        return $rows;
    }

    /**
     * One side of a change, as a line of text.
     *
     * A list or an object is written as JSON rather than flattened: a role's permissions changing
     * is exactly the sort of entry somebody reads this log for, and "Array" would tell them nothing.
     */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
        };
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
