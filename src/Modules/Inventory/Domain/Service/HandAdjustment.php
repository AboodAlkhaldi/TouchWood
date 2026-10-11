<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Service;

use Modules\Inventory\Domain\Exception\InvalidInventoryInput;
use Modules\Inventory\Domain\Exception\NoteRequired;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\ValueObject\MovementReason;

/**
 * "Add" or "Remove" by hand, with a reason (inventory.md §1.5), as a signed change of in stock. Each
 * reason goes one way (owner, 2026-10-11): pieces **received** are added; **damaged or lost** and an
 * **offline sale** are removed; a **correction** goes either way and says why in a note. So the stock's
 * history always reads right. Pure: what the stock is is the caller's to check.
 */
final class HandAdjustment
{
    public const string ADD = 'ADD';

    public const string REMOVE = 'REMOVE';

    /** A note's longest, as the ledger keeps it. */
    public const int NOTE_MAX = 500;

    /**
     * A note as the ledger keeps it: trimmed, and none when nothing is left.
     */
    public static function note(?string $note): ?string
    {
        $note = $note === null ? '' : trim($note);

        return $note === '' ? null : $note;
    }

    /**
     * @param  string|null  $note  as `note()` gives it
     * @return int the change: above 0 to add, below 0 to remove
     *
     * @throws QuantityInvalid|InvalidInventoryInput|NoteRequired
     */
    public static function change(string $direction, int $quantity, string $reason, ?string $note): int
    {
        if ($quantity < 1) {
            throw new QuantityInvalid;
        }

        $sign = match ($direction) {
            self::ADD => 1,
            self::REMOVE => -1,
            default => throw new InvalidInventoryInput('direction'),
        };

        $allowed = match (MovementReason::tryFrom($reason)) {
            MovementReason::Received => [1],
            MovementReason::DamagedLost, MovementReason::OfflineSale => [-1],
            MovementReason::Correction => [1, -1],
            // A stocktake, a shipment, a return, the file and the provider are not hand reasons.
            default => throw new InvalidInventoryInput('reason'),
        };

        if (! in_array($sign, $allowed, true)) {
            throw new InvalidInventoryInput('reason');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidInventoryInput('note');
        }

        if ($reason === MovementReason::Correction->value && ($note === null || trim($note) === '')) {
            throw new NoteRequired;
        }

        return $sign * $quantity;
    }
}
