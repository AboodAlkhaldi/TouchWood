<?php

namespace Modules\Platform\Application\Audit;

use Modules\Platform\Domain\Model\Store;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Audit entries for store changes. A store holds no personal data, so every value is kept.
 */
final class StoreAudit
{
    private const string SUBJECT = 'platform.store';

    /**
     * @return array<string, int|string|array{ar: string, en: string}>
     */
    public static function attributes(Store $store): array
    {
        return [
            'code' => $store->code()->value,
            'name' => ['ar' => $store->name()->ar, 'en' => $store->name()->en],
            'country' => $store->country()->value,
            'currency' => $store->currency()->value,
            'tax_rate_basis_points' => $store->taxRate()->basisPoints,
            'timezone' => $store->timezone()->identifier,
            'position' => $store->position(),
        ];
    }

    public static function created(Store $store): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach (self::attributes($store) as $attribute => $value) {
            $changes->changed($attribute, null, $value);
        }

        return new AuditEntryDto('platform.store.created', self::SUBJECT, $store->id()->value, $store->id()->value, $changes);
    }

    /**
     * @param  array<string, int|string|array{ar: string, en: string}>  $before  attributes() taken before the change
     * @param  list<string>  $changed
     */
    public static function updated(Store $store, array $before, array $changed): AuditEntryDto
    {
        $after = self::attributes($store);
        $changes = AuditChanges::none();

        foreach ($changed as $attribute) {
            $changes->changed($attribute, $before[$attribute] ?? null, $after[$attribute] ?? null);
        }

        return new AuditEntryDto('platform.store.updated', self::SUBJECT, $store->id()->value, $store->id()->value, $changes);
    }
}
