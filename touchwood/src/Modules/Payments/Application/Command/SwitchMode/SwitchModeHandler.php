<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Command\SwitchMode;

use Carbon\CarbonImmutable;
use Modules\Payments\Application\Gateway\GatewayChange;
use Modules\Payments\Application\PaymentsAudit;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Domain\Exception\GatewayNotSetUp;
use Modules\Payments\Domain\Exception\InvalidPaymentsInput;
use Modules\Payments\Domain\Exception\NoGatewayKey;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Public\Enums\PaymentMode;
use Shared\Application\Unauthorized;

/**
 * Test ↔ live (payments.md §1.2): refused while the mode asked for has no key and secret of its own
 * (owner, 2026-10-29, gap 8.2 (a)); on or off stays as it is.
 */
final readonly class SwitchModeHandler
{
    public const string PERMISSION = PaymentsPermissions::GATEWAY_MANAGE;

    public function __construct(
        private GatewayChange $change,
        private Gateways $gateways,
    ) {}

    /**
     * @throws Unauthorized|InvalidPaymentsInput|GatewayNotSetUp|NoGatewayKey
     */
    public function handle(SwitchMode $command): void
    {
        [$store] = $this->change->authorize($command->storeId);
        $mode = PaymentMode::tryFrom(strtoupper(trim($command->mode))) ?? throw new InvalidPaymentsInput('mode', 'TEST or LIVE');

        $this->change->run($store->id, function () use ($store, $mode): array {
            $gateway = $this->gateways->of($store->id) ?? throw new GatewayNotSetUp;

            if ($gateway->mode === $mode) {
                return [null, []];
            }

            if ($this->gateways->currentKey($store->id, $mode) === null) {
                throw new NoGatewayKey($mode);
            }

            $this->gateways->save($gateway->inMode($mode), CarbonImmutable::now()->toDateTimeImmutable());

            return [null, [PaymentsAudit::entry('gateway', 'mode_switched', $store->id, $store->id, ['mode' => $gateway->mode->value], ['mode' => $mode->value])]];
        });
    }
}
