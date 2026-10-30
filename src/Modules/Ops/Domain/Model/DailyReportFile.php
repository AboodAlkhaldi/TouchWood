<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use DateTimeImmutable;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Domain\ValueObject\StoreId;

/**
 * A daily report as kept (ops.md §1.11, §5): one store's, or every store's (no store), its day, who made
 * it and when, the language it was written in, and its PDF in the app's private files - kept twelve
 * months, to open again exactly as it was.
 */
final readonly class DailyReportFile
{
    /** Where its PDF lies: the year and month it was made, then its id. */
    public const string FILE = '/\Aops\/daily-reports\/\d{4}\/\d{2}\/[0-9a-z]{26}\.pdf\z/';

    /** @throws InvalidOpsInput */
    public function __construct(
        public string $id,
        /** Null: every store's report. */
        public ?StoreId $store,
        /** The report's day, `Y-m-d`: the store's own, or the base store's for every store. */
        public string $day,
        public bool $pastDay,
        public string $madeBy,
        public DateTimeImmutable $madeAt,
        public string $locale,
        public string $file,
        public int $bytes,
        public ?DateTimeImmutable $emailedAt = null,
    ) {
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidOpsInput('locale', 'ar or en');
        }

        if (preg_match(self::FILE, $file) !== 1) {
            throw new InvalidOpsInput('file', 'a daily report\'s own place');
        }

        if ($bytes < 1) {
            throw new InvalidOpsInput('file', 'not empty');
        }

        if ($emailedAt !== null && $emailedAt < $madeAt) {
            throw new InvalidOpsInput('emailed_at', 'not before it was made');
        }
    }

    public static function fileFor(string $id, DateTimeImmutable $madeAt): string
    {
        return 'ops/daily-reports/'.$madeAt->format('Y/m').'/'.strtolower($id).'.pdf';
    }
}
