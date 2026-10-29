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
 *   whatever their consent (§9.3 #2);
 * - **to every customer of the store** under `ops.message.send_many` - admins only (round 1 #18) -
 *   marketing for its email (§1.3): only those who agreed get it; the bell goes to all.
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
        $store = $this->writers->store($command->storeId);
        $permission = $command->customerId === null ? OpsPermissions::MESSAGE_SEND_MANY : OpsPermissions::MESSAGE_SEND_ONE;
        $this->authorizer->authorize($permission, PermissionScope::store($store->storeId()));
        $staffId = $this->writers->staffId($permission);
        $text = WrittenText::of($command->titleAr, $command->titleEn, $command->bodyAr, $command->bodyEn);

        if ($command->customerId === null) {
            $this->writers->requireCustomers($store->storeId());
            $customerId = null;
        } else {
            $customerId = strtolower(trim($command->customerId));
            $customer = Ulids::valid($customerId) ? $this->access->customer($customerId) : null;

            if ($customer === null || $customer->anonymized) {
                throw new InvalidOpsInput('customer', 'a customer');
            }
        }

        $message = new StaffMessage((string) Str::ulid(), $store->storeId(), $staffId, $customerId, $text, $command->byBell, $command->byEmail, CarbonImmutable::now()->toDateTimeImmutable());

        $this->db->transaction(function () use ($message): void {
            $this->messages->add($message);
            $this->platform->recordAudit(WrittenAudit::staffMessageSent($message));
            $this->queue->start(Broadcast::StaffMessage, strtolower($message->id));
        });

        return strtolower($message->id);
    }
}
