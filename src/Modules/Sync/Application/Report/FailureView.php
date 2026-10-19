<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Modules\Sync\Domain\ValueObject\ReadFailure;

/**
 * **Why a read failed, or a store is failing** (sync.md §4.1.1, Q31): Sync's own reason - its code, and
 * the sentence in the reader's language - or the provider's own reason as it came, shown under "The
 * provider said:" (`dir="auto"`). One of the two, never both.
 */
final readonly class FailureView
{
    public function __construct(
        public ?string $code,
        public ?string $text,
        public ?string $providerSaid,
    ) {}

    /** What a stored reason says: a code of Sync's written in the reader's language, else the provider's words. */
    public static function of(?string $stored, SyncWords $words): ?self
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $own = ReadFailure::tryFrom($stored);

        return $own === null
            ? new self(null, null, $stored)
            : new self($own->value, $words->line("admin_report.failures.{$own->value}"), null);
    }
}
