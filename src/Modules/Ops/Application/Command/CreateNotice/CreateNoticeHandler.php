<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\CreateNotice;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Application\Delivery\BroadcastQueue;
use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\Delivery\WrittenAudit;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\BroadcastStatus;
use Modules\Ops\Domain\Model\Notice;
use Modules\Ops\Domain\Repository\Notices;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Writes an offer notice (ops.md §1.7) under `ops.notice.manage` in its store: sent now - its first
 * batch queued when it is saved - or kept until its time. Refused (§7): no channel, a time already
 * past, a store with no customer to send to. Audited in the same transaction.
 */
final readonly class CreateNoticeHandler
{
    public const string PERMISSION = OpsPermissions::NOTICE_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private Notices $notices,
        private ConnectionInterface $db,
        private BroadcastQueue $queue,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the notice's id
     *
     * @throws InvalidOpsInput|Unauthorized
     */
    public function handle(CreateNotice $command): string
    {
        $store = $this->writers->store($command->storeId);
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store->storeId()));
        $staffId = $this->writers->staffId(self::PERMISSION);

        $text = WrittenText::of($command->titleAr, $command->titleEn, $command->bodyAr, $command->bodyEn, $command->link);
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $notice = Notice::write((string) Str::ulid(), $store->storeId(), $text, $command->byBell, $command->byEmail, Writers::sendAt($command->sendAt, $store), $staffId, $now);
        $this->writers->requireCustomers($store->storeId());

        $this->db->transaction(function () use ($notice): void {
            $this->notices->add($notice);
            $this->platform->recordAudit(WrittenAudit::noticeCreated($notice));

            if ($notice->status === BroadcastStatus::Sending) {
                $this->queue->start(Broadcast::Notice, $notice->id);
            }
        });

        return $notice->id;
    }
}
