<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value that is not one (sync.md §7): a store that does not exist, an address that is not HTTPS, an
 * empty key or secret, a provider we do not have. Named by its field, as Catalog's invalid attribute is -
 * and one of the provider's own settings by its own name (`settings`, the setting named; Q34).
 */
final class InvalidSyncInput extends SyncError
{
    /** The field of a provider's own setting refused; the setting is named beside it. */
    public const string SETTINGS = 'settings';

    public function __construct(
        public readonly string $attribute,
        public readonly ?string $setting = null,
    ) {
        parent::__construct($setting === null ? "Invalid {$attribute}." : "Invalid provider setting {$setting}.");
    }

    public function type(): string
    {
        return 'sync.invalid_sync_input';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        // A setting's own message names it (`rules.setting`), as the provider's adapter names it.
        return $this->setting === null ? ['attribute' => $this->attribute] : ['attribute' => $this->attribute, 'setting' => $this->setting, 'rule' => 'setting'];
    }
}
