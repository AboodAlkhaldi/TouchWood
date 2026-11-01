<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use DateTimeImmutable;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;

/**
 * The gateway, as an adapter (payments.md §1.2): every call logged (rule 13), its answers exact. The charge
 * joins it with the attempt that claims it (step 10, rule 4).
 */
interface GatewayClient
{
    /**
     * Opens a session: it charges nothing. Not repeated: a page that gets no answer offers Retry.
     *
     * @param  string  $amount  a decimal, as `Money::toDecimal` writes it
     *
     * @throws GatewayKeyRefused|GatewayRefused|GatewayUnreachable
     */
    public function openSession(GatewayAccess $access, string $amount, string $currencyCode, string $locale, DateTimeImmutable $expiresAt): OpenedSession;

    /**
     * What MyFatoorah says of a payment: asked three times, five seconds apart (rule 9).
     *
     * @throws GatewayKeyRefused|GatewayRefused|GatewayUnreachable
     */
    public function paymentDetails(GatewayAccess $access, string $paymentId): PaymentAnswer;

    /**
     * What MyFatoorah says of a session: whether it was used, and what came of it (rule 4). Asked as above.
     *
     * @throws GatewayKeyRefused|GatewayRefused|GatewayUnreachable
     */
    public function sessionDetails(GatewayAccess $access, string $sessionId): SessionAnswer;

    /**
     * @throws GatewayKeyRefused|GatewayRefused|GatewayUnreachable
     */
    public function registerApplePayDomain(GatewayAccess $access, string $domain): void;
}
