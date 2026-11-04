<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\CheckAttempt;

use Modules\Payments\Application\Attempt\AttemptChecks;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Domain\Exception\AttemptNotFound;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Public\Dto\PaymentStateDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Staff's "Ask the gateway now" (payments.md §3, §4.2): the answer is written as any other, and said in a sentence.
 */
final readonly class CheckAttemptHandler
{
    public const string PERMISSION = PaymentsPermissions::VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private Attempts $attempts,
        private AttemptChecks $checks,
    ) {}

    /**
     * @throws Unauthorized|AttemptNotFound
     */
    public function handle(CheckAttempt $command): PaymentStateDto
    {
        $id = strtolower($command->attemptId);
        $attempt = Ulids::valid($id) ? $this->attempts->find($id) : null;

        if ($attempt === null) {
            throw new AttemptNotFound;
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store(StoreId::fromString($attempt->storeId)));

        return $this->checks->staffAsked($attempt->id);
    }
}
