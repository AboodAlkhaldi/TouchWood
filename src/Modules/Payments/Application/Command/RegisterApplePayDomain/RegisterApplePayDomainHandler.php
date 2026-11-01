<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\RegisterApplePayDomain;

use Carbon\CarbonImmutable;
use Modules\Payments\Application\Gateway\GatewayAccesses;
use Modules\Payments\Application\Gateway\GatewayChange;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Application\PaymentsAudit;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayNotSetUp;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoGatewayKey;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Enums\PaymentMode;
use Shared\Application\Unauthorized;

final readonly class RegisterApplePayDomainHandler
{
    public const string PERMISSION = PaymentsPermissions::GATEWAY_MANAGE;

    private const string DOMAIN = '/\A(?=.{1,253}\z)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/';

    public function __construct(
        private GatewayChange $change,
        private Gateways $gateways,
        private GatewayAccesses $accesses,
        private GatewayClient $client,
    ) {}

    /**
     * @throws Unauthorized|InvalidPaymentsInput|GatewayNotSetUp|NoGatewayKey|GatewayRefused|GatewayUnreachable
     */
    public function handle(RegisterApplePayDomain $command): void
    {
        [$store] = $this->change->authorize($command->storeId);
        $mode = PaymentMode::tryFrom(strtoupper(trim($command->mode))) ?? throw new InvalidPaymentsInput('mode', 'TEST or LIVE');
        $domain = strtolower(trim($command->domain));

        if (preg_match(self::DOMAIN, $domain) !== 1) {
            throw new InvalidPaymentsInput('domain', 'a domain, such as shop.example.com');
        }

        if ($this->gateways->of($store->id) === null) {
            throw new GatewayNotSetUp;
        }

        $access = $this->accesses->current($store, $mode) ?? throw new NoGatewayKey($mode);

        // Outside the lock: nothing waits on MyFatoorah's answer. Asked again even when listed: Apple may
        // need a domain revalidated (§1.2).
        try {
            $this->client->registerApplePayDomain($access, $domain);
        } catch (GatewayKeyRefused) {
            $this->accesses->refused($access);

            throw new GatewayRefused('MyFatoorah refused the key.');
        }

        $this->change->run($store->id, function () use ($store, $mode, $domain): array {
            $gateway = $this->gateways->of($store->id) ?? throw new GatewayNotSetUp;

            if ($gateway->applePayOn($domain, $mode)) {
                return [null, []];
            }

            $this->gateways->save($gateway->withApplePayOn($domain, $mode), CarbonImmutable::now()->toDateTimeImmutable());

            return [null, [PaymentsAudit::entry('gateway', 'apple_pay_registered', $store->id, $store->id, [], ['domain' => $domain, 'mode' => $mode->value])]];
        });
    }
}
