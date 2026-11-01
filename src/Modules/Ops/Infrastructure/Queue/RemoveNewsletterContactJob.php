<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Newsletter\NewsletterSync;
use Psr\Log\LoggerInterface;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * Takes an email off a store's newsletter list at the provider, once its row is gone (ops.md §1.9);
 * for an anonymized account, deletes the contact too (§6). The email waits in the job until it runs:
 * the row that held it is already deleted. So the job is **encrypted**: an address never lies readable in
 * the `jobs` or `failed_jobs` tables. The failed jobs screen reads the job's name and tries from the outer
 * payload (`displayName`, `maxTries`), which stay plain, and a retry pushes the payload back as it is.
 *
 * Brevo's reference does not say what it answers when the email is not on the list or is no contact
 * (https://developers.brevo.com/reference/removecontactfromlist: a 400's codes include "Contact email
 * not found", never tied to this endpoint), so no such answer is taken as done: after the last attempt
 * the job waits on the failed jobs screen, with Brevo's status and code - never retried for ever, never
 * silent.
 */
final class RemoveNewsletterContactJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: Brevo away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public string $email,
        public string $storeId,
        public string $locale,
        public bool $forget,
    ) {}

    public function handle(NewsletterSync $sync): void
    {
        $sync->remove($this->email, StoreId::fromString($this->storeId), $this->locale, $this->forget);
    }

    public function failed(Throwable $error): void
    {
        // The reason, never the email: ProviderFailed names only Brevo's status and code.
        app(LoggerInterface::class)->warning('Ops: a newsletter contact could not be taken off a list at the provider; the job waits on the failed jobs screen.', [
            'store' => $this->storeId,
            'locale' => $this->locale,
            'reason' => $error->getMessage(),
        ]);
    }
}
