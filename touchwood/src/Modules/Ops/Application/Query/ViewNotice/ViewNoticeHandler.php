<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewNotice;

use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\NoticeNotFound;
use Modules\Ops\Domain\Model\Notice;
use Modules\Ops\Domain\Repository\Notices;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/** One notice (ops.md §1.7, §4), under `ops.notice.manage` in its store. */
final readonly class ViewNoticeHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private Notices $notices,
    ) {}

    /** @throws NoticeNotFound|Unauthorized */
    public function handle(ViewNotice $query): Notice
    {
        $stores = $this->writers->storesHolding(OpsPermissions::NOTICE_MANAGE);
        $notice = $this->notices->find($query->noticeId);

        if ($notice === null || ! Writers::among($notice->store, $stores)) {
            throw new NoticeNotFound;
        }

        $this->authorizer->authorize(OpsPermissions::NOTICE_MANAGE, PermissionScope::store($notice->store));

        return $notice;
    }
}
