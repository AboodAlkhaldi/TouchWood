<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value that is not one (sync.md §7): a store that does not exist, an address that is not HTTPS, an
 * empty key or secret, a provider we do not have. Named by its field, as Catalog's invalid attribute is -
 * and one of the provider's own settings by its own name (`settings`, the setting named; Q34). An address
 * may also name the rule it broke (`rule`: HTTPS, or the provider's own shape, sync.md §2.4), said in that
 * rule's own words under the Address box.
 */
final class InvalidSyncInput extends SyncError
{
    /** The field of a provider's own setting refused; the setting is named beside it. */
    public const string SETTINGS = 'settings';

    /** An address that does not start with `https://`. */
    public const string ADDRESS_HTTPS = 'address_https';

    /** An address with a query (`?`) or a fragment (`#`), where the provider's takes none (Odoo's, §2.4). */
    public const string ADDRESS_QUERY = 'address_query';

    /** An address whose path is not the provider's (Odoo's ends in `/touchwood/v1`, §2.4). */
    public const string ADDRESS_PATH = 'address_path';

    public function __construct(
        public readonly string $attribute,
        public readonly ?string $setting = null,
        public readonly ?string $rule = null,
    ) {
        parent::__construct($setting === null ? "Invalid {$attribute}.".($rule === null ? '' : " ({$rule})") : "Invalid provider setting {$setting}.");
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
        return match (true) {
            $this->setting !== null => ['attribute' => $this->attribute, 'setting' => $this->setting, 'rule' => 'setting'],
            $this->rule !== null => ['attribute' => $this->attribute, 'rule' => $this->rule],
            default => ['attribute' => $this->attribute],
        };
    }
}
