<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\B2B\Domain\Repository\ApplicationReferenceCounter;
use Modules\B2B\Domain\ValueObject\ApplicationReference;

/**
 * The year's count, one row per year in `b2b.application_reference_counters` (b2b.md §5, amendment
 * 14(g)).
 *
 * One statement adds the year's row or moves it on, and returns the number. The update locks the
 * row until the transaction ends — a second send the same year waits for the first — and a
 * rolled-back send takes its number back with it, so a year's numbers have no gaps. That is only
 * true inside a transaction, which is why it refuses to run outside one.
 */
final readonly class DatabaseApplicationReferenceCounter implements ApplicationReferenceCounter
{
    private const string TABLE = 'b2b.application_reference_counters';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function next(int $year): ApplicationReference
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('An application number is taken only inside the send\'s transaction.');
        }

        $row = $this->db->selectOne(
            'insert into '.self::TABLE.' (year, last_number) values (?, 1) '
            .'on conflict (year) do update set last_number = '.self::TABLE.'.last_number + 1 '
            .'returning last_number',
            [$year],
        );

        return ApplicationReference::of($year, (int) $row->last_number);
    }
}
