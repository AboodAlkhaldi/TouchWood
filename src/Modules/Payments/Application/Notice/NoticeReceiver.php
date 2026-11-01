<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Notice;

use Carbon\CarbonImmutable;
use Modules\Payments\Application\Gateway\NoticeReader;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Domain\Repository\Notices;
use Modules\Payments\Public\Enums\PaymentMode;
use Psr\Log\LoggerInterface;

/**
 * MyFatoorah's notice at a store's and mode's own address (payments.md §1.2, §5): machine to machine, the
 * signature its authority. Only a signed notice is kept, and a bad one only logged. Kept once however often
 * it is sent. What it sets off is the attempt's, which asks the gateway (rule 1, step 10): a notice never
 * changes anything by itself.
 */
final readonly class NoticeReceiver
{
    public function __construct(
        private Gateways $gateways,
        private NoticeReader $reader,
        private Notices $notices,
        private LoggerInterface $logger,
    ) {}

    public function receive(string $storeId, string $mode, ?string $signature, string $body): NoticeOutcome
    {
        $store = strtolower($storeId);
        $paymentMode = PaymentMode::from(strtoupper($mode));
        // Every secret still kept: a notice for an open attempt made with an earlier key is still good (rule 14).
        $reading = $this->reader->read($body, $signature, $this->gateways->secrets($store, $paymentMode));

        if (! $reading->signed) {
            $this->logger->warning('A payment notice failed its signature check.', ['store_id' => $store, 'mode' => $paymentMode->value]);

            return NoticeOutcome::Refused;
        }

        if ($reading->reference !== null) {
            $this->notices->add($store, $paymentMode, $reading->reference, $reading->payload, CarbonImmutable::now()->toDateTimeImmutable());
        }

        return NoticeOutcome::Accepted;
    }
}
