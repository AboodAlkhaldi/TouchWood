<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\ChangeNotice;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Application\Delivery\BroadcastQueue;
use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\NoticeAlreadyStarted;
use Modules\Ops\Domain\Exception\NoticeNotFound;
use Modules\Ops\Domain\Model\BroadcastStatus;
use Modules\Ops\Domain\Repository\Notices;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Changes a notice until it starts sending (ops.md §1.7), under `ops.notice.manage` in its store. The
 * row is locked: a change and the minute that starts it never both win. A notice already sending,
 * sent or cancelled is refused.
 */
final readonly class ChangeNoticeHandler
{
    public const string PERMISSION = OpsPermissions::NOTICE_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private Notices $notices,
        private ConnectionInterface $db,
        private BroadcastQueue $queue,
    ) {}

    /**
     * @throws InvalidOpsInput|NoticeAlreadyStarted|NoticeNotFound|Unauthorized
     */
    public function handle(ChangeNotice $command): void
    {
        $found = $this->notices->find($command->noticeId) ?? throw new NoticeNotFound;
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($found->store));
        $this->writers->staffId(self::PERMISSION);
        $store = $this->writers->store($found->store->value);
        $text = WrittenText::of($command->titleAr, $command->titleEn, $command->bodyAr, $command->bodyEn, $command->link);
        $sendAt = Writers::sendAt($command->sendAt, $store);

        $this->db->transaction(function () use ($found, $text, $command, $sendAt): void {
            $notice = $this->notices->lock($found->id) ?? throw new NoticeNotFound;
            $changed = $notice->changed($text, $command->byBell, $command->byEmail, $sendAt, CarbonImmutable::now()->toDateTimeImmutable());
            $this->notices->update($changed);

            if ($changed->status === BroadcastStatus::Sending) {
                $this->queue->start(Broadcast::Notice, $changed->id);
            }
        });
    }
}
