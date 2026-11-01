<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\SendStaffMessage;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Application\Delivery\BroadcastQueue;
use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\Delivery\WrittenAudit;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\StaffMessage;
use Modules\Ops\Domain\Repository\StaffMessages;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Sends a staff member's message (ops.md §1.8), at once, on the queue:
 *
 * - **to one customer** under `ops.message.send_one` in the store - a service message, its email sent
 *   whatever their consent (§9.3 #2) - registered in **this** store, so the entry lands in the bell they
 *   see (owner, 2026-11-01), where the writer holds send_one and may view customers (owner, 2026-10-30);
 *   any other is refused as an unknown one;
 * - **to every customer of the store, or the members of one of its segments** (owner, 2026-11-01) under
 *   `ops.message.send_many` - admins only (round 1 #18) - marketing for its email (§1.3): only those who
 *   agreed get it; the bell goes to all. Another store's segment and an empty audience are refused as for
 *   a notice (Writers::audience).
 *
 * Recorded with who sent it, to whom, the channels and when, and audited in the same transaction;
 * nothing can be recalled.
 */
final readonly class SendStaffMessageHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private AccessApi $access,
        private StaffMessages $messages,
        private ConnectionInterface $db,
        private BroadcastQueue $queue,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the message's id
     *
     * @throws InvalidOpsInput|Unauthorized
     */
    public function handle(SendStaffMessage $command): string
    {
        self::requireAudience($command);
        $store = $this->writers->store($command->storeId);
        $permission = $command->customerId === null ? OpsPermissions::MESSAGE_SEND_MANY : OpsPermissions::MESSAGE_SEND_ONE;
        $this->authorizer->authorize($permission, PermissionScope::store($store->storeId()));
        $staffId = $this->writers->staffId($permission);
        $text = WrittenText::of($command->titleAr, $command->titleEn, $command->bodyAr, $command->bodyEn);

        if ($command->customerId === null) {
            $segmentId = $this->writers->audience($store->storeId(), $command->segmentId);
            $customerId = null;
        } else {
            $segmentId = null;
            $customerId = strtolower(trim($command->customerId));
            $customer = Ulids::valid($customerId) ? $this->access->customer($customerId) : null;

            // Only a customer of this store, where the writer may send to one and view customers.
            if ($customer === null || strtolower($customer->homeStoreId) !== $store->id || ! $this->writers->mayWriteToOne($customer)) {
                throw new InvalidOpsInput('customer', 'a customer');
            }
        }

        $message = new StaffMessage((string) Str::ulid(), $store->storeId(), $staffId, $customerId, $text, $command->byBell, $command->byEmail, CarbonImmutable::now()->toDateTimeImmutable(), $segmentId);

        $this->db->transaction(function () use ($message): void {
            $this->messages->add($message);
            $this->platform->recordAudit(WrittenAudit::staffMessageSent($message));
            $this->queue->start(Broadcast::StaffMessage, strtolower($message->id));
        });

        return strtolower($message->id);
    }

    /**
     * The audience said, and what it names: a customer for `ONE`, a segment for `SEGMENT`, neither for `STORE`.
     *
     * @throws InvalidOpsInput
     */
    private static function requireAudience(SendStaffMessage $command): void
    {
        $matches = match ($command->audience) {
            'ONE' => $command->customerId !== null && $command->segmentId === null,
            'SEGMENT' => $command->customerId === null && $command->segmentId !== null,
            'STORE' => $command->customerId === null && $command->segmentId === null,
            default => false,
        };

        if (! $matches) {
            throw new InvalidOpsInput('audience', 'ONE with a customer, SEGMENT with a segment, or STORE with neither');
        }
    }
}
