<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Modules\Sync\Domain\ValueObject\ProviderReason;
use Shared\Domain\Error\ErrorCategory;

/**
 * Saving a connection whose test read failed (sync.md §1.2, §7; owner, 2026-10-13): nothing is saved,
 * the reason named - never the key or the secret. A read that fails with it keeps the reason as stored.
 *
 * The reason is an adapter's code and its values (`ProviderReason`, stage 5 step 10) - said in the page's
 * language through its rule, `sync::errors.connection_failed.rules.<code>` - or a provider's own words as
 * they came, said in the one sentence.
 */
final class ConnectionFailed extends SyncError
{
    /** The reason as stored: a `ProviderReason`'s code and values, or the provider's words. */
    public readonly string $reason;

    public readonly ?ProviderReason $coded;

    /**
     * @param  string|null  $sentence  a coded reason's English sentence, for the developers' message (the
     *                                 failed jobs screen, the log) - the adapter writes it from the lang file;
     *                                 without one, the code and its values are said as stored
     */
    public function __construct(string|ProviderReason $reason, ?string $sentence = null)
    {
        $this->coded = $reason instanceof ProviderReason ? $reason : null;
        $this->reason = $reason instanceof ProviderReason ? $reason->stored() : $reason;

        parent::__construct('The provider could not be read: '.($this->coded === null ? $this->reason : ($sentence ?? $this->reason)));
    }

    public function type(): string
    {
        return 'sync.connection_failed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        if ($this->coded === null) {
            return ['reason' => $this->reason];
        }

        return ['reason' => $this->coded->code, 'rule' => strtolower($this->coded->code), ...$this->coded->values];
    }
}
