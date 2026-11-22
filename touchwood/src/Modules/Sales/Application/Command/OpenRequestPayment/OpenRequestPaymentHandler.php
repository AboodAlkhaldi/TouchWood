<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\OpenRequestPayment;

use Carbon\CarbonImmutable;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\PaymentSessionDto;
use Modules\Payments\Public\Dto\SessionRequestDto;
use Modules\Sales\Application\Order\OrderRequests;
use Modules\Sales\Application\Order\OwnRequestOrder;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\PaymentInProgress;
use Modules\Sales\Domain\Exception\PaymentLinkEnded;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/**
 * A session for a request's page (payments.md §1.5), lasting the request's time **plus the window and the wait**, so
 * a Pay at its last minute has its full time. While a payment on it is open there is no form.
 */
final readonly class OpenRequestPaymentHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_MANAGE_OWN;

    public function __construct(
        private OwnRequestOrder $own,
        private OrderRequests $requests,
        private PaymentsApi $payments,
    ) {}

    /** @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|PaymentLinkEnded|PaymentInProgress|DomainError */
    public function handle(OpenRequestPayment $command): PaymentSessionDto
    {
        [$orderId, $store, $customerId, $missing] = $this->own->authorize(self::PERMISSION, $command->storeId, $command->number);
        $request = $this->requests->live($orderId, $missing) ?? throw new PaymentLinkEnded;

        if ($this->payments->paymentOpen($orderId)) {
            throw new PaymentInProgress;
        }

        $timers = $this->payments->timers($store);

        return $this->payments->openSession(new SessionRequestDto(
            $store, $request->requestId, $customerId, $request->amount, $command->locale,
            CarbonImmutable::instance($request->expiresAt)->addMinutes($timers->window + $timers->wait),
            $request->mode,
        ));
    }
}
