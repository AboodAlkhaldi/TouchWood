<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Modules\Sync\Domain\ValueObject\ProviderReason;
use Modules\Sync\Domain\ValueObject\ReadFailure;

/**
 * **Why a read failed, or a store is failing** (sync.md §4.1.1, Q31): Sync's own reason - its code, and
 * the sentence in the reader's language - or the provider's reason, shown under "The provider said:"
 * (`dir="auto"`): its adapter's code and values written in the reader's language (stage 5 step 10), or
 * its words as they came - a reason stored before the codes too. One of the two, never both.
 */
final readonly class FailureView
{
    public function __construct(
        public ?string $code,
        public ?string $text,
        public ?string $providerSaid,
    ) {}

    /** What a stored reason says: a code of Sync's or an adapter's written in the reader's language, else the provider's words. */
    public static function of(?string $stored, SyncWords $words): ?self
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $own = ReadFailure::tryFrom($stored);

        if ($own !== null) {
            return new self($own->value, $words->line("admin_report.failures.{$own->value}"), null);
        }

        $provider = ProviderReason::fromStored($stored);

        return new self(null, null, $provider === null ? $stored : $words->line("provider_failures.{$provider->code}", $provider->values));
    }
}
