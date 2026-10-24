<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Reason;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Domain\Repository\RejectReasons;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A change to the ready reasons (feedback.md §2.4 B4): **one list for every store**, so changed only by an
 * admin who holds `feedback.settings.update` in every store (the owner's answer, 2026-10-24, read as
 * Catalog's shared lists are — catalog.md decision 36); the list's lock first, then the change and its
 * audit in one transaction.
 */
final readonly class ReasonsChange
{
    public const string PERMISSION = FeedbackPermissions::SETTINGS_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private RejectReasons $reasons,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /** @throws Unauthorized */
    public function authorize(): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::allStores());

        if ($this->actors->current()->type !== ActorType::Staff) {
            throw new Unauthorized(self::PERMISSION);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): array{T, list<AuditEntryDto>}  $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        return $this->db->transaction(function () use ($work): mixed {
            $this->reasons->lock();
            [$result, $entries] = $work();

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }

            return $result;
        }, 3);
    }
}
