<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

use DateTimeImmutable;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;

/**
 * The gateway, as an adapter (payments.md §1.2): every call logged (rule 13), its answers exact.
 */
interface GatewayClient
{
    /**
     * Opens a session: it charges nothing. Not repeated: a page that gets no answer offers Retry.
     *
     * @param  string  $amount  a decimal, as `Money::toDecimal` writes it
     *
     * @throws GatewayKeyRefused|KeyPermissionMissing|GatewayRefused|GatewayUnreachable
     */
    public function openSession(GatewayAccess $access, string $amount, string $currencyCode, string $locale, DateTimeImmutable $expiresAt): OpenedSession;

    /**
     * The charge, with its idempotency key: repeated three times on no answer, always with the same key (rule 4).
     * Only the **first** call's refusal is a refusal; a refusal after a repeat - the session already used - is
     * "we do not know", settled by asking about the session.
     *
     * @throws GatewayKeyRefused|KeyPermissionMissing|GatewayRefused|GatewayUnreachable
     */
    public function charge(GatewayAccess $access, ChargeRequest $request): ChargeAnswer;

    /**
     * What MyFatoorah says of a payment: asked three times, five seconds apart (rule 9).
     *
     * @throws GatewayKeyRefused|KeyPermissionMissing|GatewayRefused|GatewayUnreachable
     */
    public function paymentDetails(GatewayAccess $access, string $paymentId): PaymentAnswer;

    /**
     * What MyFatoorah says of a session: whether it was used, and what came of it (rule 4). Asked as above.
     * "Never charged" is said only when it is clear: used, or with a result we cannot read, is "we do not know".
     *
     * @throws GatewayKeyRefused|KeyPermissionMissing|GatewayRefused|GatewayUnreachable
     */
    public function sessionDetails(GatewayAccess $access, string $sessionId): SessionAnswer;

    /**
     * @throws GatewayKeyRefused|KeyPermissionMissing|GatewayRefused|GatewayUnreachable
     */
    public function registerApplePayDomain(GatewayAccess $access, string $domain): void;
}
