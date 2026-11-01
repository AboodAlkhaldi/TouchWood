<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Notice;

use Carbon\CarbonImmutable;
use Modules\Payments\Application\Attempt\AttemptChecks;
use Modules\Payments\Application\Gateway\NoticeReader;
use Modules\Payments\Domain\Model\Attempt;
use Modules\Payments\Domain\Repository\Attempts;
use Modules\Payments\Domain\Repository\Notices;
use Modules\Payments\Public\Enums\PaymentMode;
use Psr\Log\LoggerInterface;

/**
 * What a kept notice sets off (payments.md §1.1 rules 1, 7): the attempt it names is asked about. Found from the
 * invoice and our own id, both signed, in the notice's own store and mode. Anything else is applied to nothing.
 */
final readonly class NoticeHandling
{
    public function __construct(
        private Notices $notices,
        private Attempts $attempts,
        private NoticeReader $reader,
        private AttemptChecks $checks,
        private LoggerInterface $logger,
    ) {}

    public function handle(string $noticeId): void
    {
        $notice = $this->notices->find($noticeId);

        if ($notice === null || $notice->handledAt !== null) {
            return;
        }

        $facts = $this->reader->facts($notice->payload);
        $attempt = $facts->attemptId === null ? null : $this->attempts->find($facts->attemptId);

        if ($attempt === null && $facts->invoiceId !== null) {
            $attempt = $this->attempts->byInvoice($notice->mode, $facts->invoiceId);
        }

        if ($attempt === null || ! $this->belongs($attempt, $notice->storeId, $notice->mode, $facts->invoiceId, $facts->attemptId)) {
            // Not ours, or made outside this system: MyFatoorah's portal sends every invoice's notices here.
            $this->logger->info('A payment notice names no attempt of this store and mode.', ['notice_id' => $noticeId]);
        } elseif (! $this->checks->noticed($attempt, $facts->paymentId)) {
            // Nothing learned: the hourly look tries again, for a day. MyFatoorah will not send this one twice.
            return;
        }

        $this->notices->markHandled($noticeId, CarbonImmutable::now()->toDateTimeImmutable());
    }

    private function belongs(Attempt $attempt, string $storeId, PaymentMode $mode, ?string $invoiceId, ?string $attemptId): bool
    {
        return $attempt->storeId === $storeId
            && $attempt->mode === $mode
            && ($attemptId === null || $attemptId === $attempt->id)
            && ($invoiceId === null || $attempt->invoiceId === null || $invoiceId === $attempt->invoiceId);
    }
}
