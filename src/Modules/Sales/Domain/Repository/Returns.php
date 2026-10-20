<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Repository;

use DateTimeImmutable;
use Modules\Sales\Domain\Model\ReturnState;

/**
 * A delivered order's returns (sales.md §1.8, §5). Every change locks the order first, then the return.
 */
interface Returns
{
    /** @return array{string, string}|null the return's order and store */
    public function orderOf(string $returnId): ?array;

    public function lock(string $returnId): ?ReturnState;

    /** The order's return deadline, fixed when it was delivered; null before. */
    public function deadline(string $orderId): ?DateTimeImmutable;

    /**
     * The order's lines that may be returned — every bought part and the gift — with how many pieces each
     * holds and how many other returns count (§1.8: asked in one requested or accepted; received in one
     * received or completed).
     *
     * @return array<string, array{quantity: int, counted: int}> by order line id
     */
    public function returnable(string $orderId): array;

    /**
     * @param  array<string, int>  $lines  order line id => pieces asked
     */
    public function request(string $returnId, string $orderId, string $storeId, string $reason, ?string $reasonOther, array $lines, DateTimeImmutable $at): void;

    public function withdraw(string $returnId, DateTimeImmutable $at): void;

    /** Accepted, or refused with staff's words the customer sees. */
    public function decide(string $returnId, string $status, ?string $staffReason, string $by, DateTimeImmutable $at): void;

    public function close(string $returnId, DateTimeImmutable $at): void;

    /**
     * The return's lines: each order line's size, whether it is the gift, the pieces asked and — once
     * received — those that came and those going back into stock.
     *
     * @return array<string, array{variantId: string, gift: bool, asked: int, received: ?int, restocked: ?int}> by order line id
     */
    public function lines(string $returnId): array;

    /**
     * @param  array<string, array{int, int}>  $lines  order line id => [received, restocked]
     */
    public function receive(string $returnId, array $lines, DateTimeImmutable $at): void;

    /**
     * What a completion refunds by (§1.8): the order's lines and their shares, the pieces of each received
     * in returns completed already, whether one carried the shipping, the shipping fee and its VAT share.
     *
     * @return array{lines: array<string, array{quantity: int, total: int, coupon: int, points: int, vat: int, gift: bool}>, before: array<string, int>, shippingRefunded: bool, shippingFee: int, shippingVat: int}
     */
    public function forRefund(string $orderId): array;

    public function complete(string $returnId, int $refundDue, bool $shippingRefunded, DateTimeImmutable $at): void;

    /** @return int how many photos the return holds */
    public function photos(string $returnId): int;

    public function addPhoto(string $returnId, string $mediaId, int $position): void;
}
