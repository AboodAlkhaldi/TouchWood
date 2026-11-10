<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\RequestRedirectCheck;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentJobs;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Repository\WaitingJobs;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Queues Check Addresses (content.md §1.15, W11): `CheckRedirectsJob` asks each line's new address and marks it.
 * **One at a time**: while one waits, a second press queues nothing (`WaitingJobs`).
 */
final readonly class RequestRedirectCheckHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECT_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private WaitingJobs $waiting,
        private ContentJobs $jobs,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return bool whether one was queued now (false: one waits already)
     *
     * @throws Unauthorized
     */
    public function handle(RequestRedirectCheck $command): bool
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        return $this->db->transaction(function (): bool {
            if (! $this->waiting->ask(WaitingJobs::CHECK_REDIRECTS, Moments::now())) {
                return false;
            }

            $this->jobs->checkRedirects();

            return true;
        });
    }
}
