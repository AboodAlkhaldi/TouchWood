<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\CancelNotice;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\NoticeAlreadyStarted;
use Modules\Ops\Domain\Exception\NoticeNotFound;
use Modules\Ops\Domain\Repository\Notices;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Cancels a notice until it starts sending (ops.md §1.7), under `ops.notice.manage` in its store. Once
 * sending starts it cannot be recalled; one cancelled already stays as it is - refused the same way.
 */
final readonly class CancelNoticeHandler
{
    public const string PERMISSION = OpsPermissions::NOTICE_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private Notices $notices,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NoticeAlreadyStarted|NoticeNotFound|Unauthorized
     */
    public function handle(CancelNotice $command): void
    {
        $found = $this->notices->find($command->noticeId) ?? throw new NoticeNotFound;
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($found->store));

        $this->db->transaction(function () use ($found): void {
            $notice = $this->notices->lock($found->id) ?? throw new NoticeNotFound;
            $this->notices->update($notice->cancelled(CarbonImmutable::now()->toDateTimeImmutable()));
        });
    }
}
