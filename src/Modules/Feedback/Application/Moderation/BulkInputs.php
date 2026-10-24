<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Moderation;

use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Shared\Infrastructure\Persistence\Ulids;

/** What a decision on many is made of (feedback.md §2.4 B2), checked before anything is read. */
final class BulkInputs
{
    /** A page of the queue at most (feedback.md §4.1 F1: up to a page, 50). */
    public const int MOST = 50;

    /**
     * @param  list<string>  $ids
     * @return list<string> each once, as sent
     *
     * @throws InvalidFeedbackAttribute
     */
    public static function ids(array $ids): array
    {
        $kept = [];

        foreach ($ids as $id) {
            $id = strtolower(trim($id));

            if (! Ulids::valid($id)) {
                throw new InvalidFeedbackAttribute('ids', 'ids of the queue');
            }

            if (in_array($id, $kept, true)) {
                throw new InvalidFeedbackAttribute('ids', 'each one once');
            }

            $kept[] = $id;
        }

        return $kept !== [] && count($kept) <= self::MOST ? $kept : throw new InvalidFeedbackAttribute('ids', '1 to '.self::MOST);
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws InvalidFeedbackAttribute
     */
    public static function action(string $action, array $allowed): string
    {
        $action = strtolower(trim($action));

        return in_array($action, $allowed, true) ? $action : throw new InvalidFeedbackAttribute('action', implode(', ', $allowed));
    }

    /** @throws InvalidFeedbackAttribute */
    public static function store(string $storeId): string
    {
        $storeId = strtolower(trim($storeId));

        return Ulids::valid($storeId) ? $storeId : throw new InvalidFeedbackAttribute('store', 'a store');
    }
}
