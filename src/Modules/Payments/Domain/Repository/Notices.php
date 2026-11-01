<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Repository;

use DateTimeImmutable;
use Modules\Payments\Domain\Model\KeptNotice;
use Modules\Payments\Public\Enums\PaymentMode;

interface Notices
{
    /**
     * Keeps a notice whose signature checked out, answering its id; null when this one was kept before
     * (MyFatoorah sends again until it hears 200).
     *
     * @param  array<array-key, mixed>  $payload  the card and contact details already removed
     */
    public function add(string $storeId, PaymentMode $mode, string $reference, array $payload, DateTimeImmutable $now): ?string;

    public function find(string $id): ?KeptNotice;

    public function markHandled(string $id, DateTimeImmutable $now): void;

    /**
     * Notices whose job was lost, oldest first (§9.6 #4).
     *
     * @return list<string> their ids
     */
    public function unhandled(DateTimeImmutable $before, int $most): array;
}
