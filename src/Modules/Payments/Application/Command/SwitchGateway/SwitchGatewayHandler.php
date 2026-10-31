<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\SwitchGateway;

use Carbon\CarbonImmutable;
use Modules\Payments\Application\Gateway\GatewayChange;
use Modules\Payments\Application\PaymentsAudit;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Domain\Exception\GatewayNotSetUp;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoGatewayKey;
use Modules\Payments\Domain\Repository\Gateways;
use Shared\Application\Unauthorized;

/**
 * The gateway on or off (payments.md §1.2): on only while its mode has a key (gap 8.2 (a)).
 */
final readonly class SwitchGatewayHandler
{
    public const string PERMISSION = PaymentsPermissions::GATEWAY_MANAGE;

    public function __construct(
        private GatewayChange $change,
        private Gateways $gateways,
    ) {}

    /**
     * @throws Unauthorized|InvalidPaymentsInput|GatewayNotSetUp|NoGatewayKey
     */
    public function handle(SwitchGateway $command): void
    {
        [$store] = $this->change->authorize($command->storeId);

        $this->change->run($store->id, function () use ($store, $command): array {
            $gateway = $this->gateways->of($store->id) ?? throw new GatewayNotSetUp;

            if ($gateway->active === $command->on) {
                return [null, []];
            }

            if ($command->on && $this->gateways->currentKey($store->id, $gateway->mode) === null) {
                throw new NoGatewayKey($gateway->mode);
            }

            $this->gateways->save($gateway->switchedTo($command->on), CarbonImmutable::now()->toDateTimeImmutable());

            return [null, [PaymentsAudit::entry('gateway', $command->on ? 'switched_on' : 'switched_off', $store->id, $store->id, ['active' => $gateway->active ? 'on' : 'off'], ['active' => $command->on ? 'on' : 'off'])]];
        });
    }
}
