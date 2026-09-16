<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Audit;

use Modules\Platform\Domain\Model\Currency;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Audit entries for currency changes. Currencies are global, so entries have no store.
 */
final class CurrencyAudit
{
    private const string SUBJECT = 'platform.currency';

    /**
     * @return array<string, int|string|array{ar: string, en: string}|null>
     */
    public static function attributes(Currency $currency): array
    {
        return [
            'code' => $currency->code()->value,
            'exponent' => $currency->exponent(),
            'name' => ['ar' => $currency->name()->ar, 'en' => $currency->name()->en],
            'abbreviation' => ['ar' => $currency->abbreviation()->ar, 'en' => $currency->abbreviation()->en],
            'sign' => $currency->sign(),
        ];
    }

    public static function created(Currency $currency): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach (self::attributes($currency) as $attribute => $value) {
            $changes->changed($attribute, null, $value);
        }

        return new AuditEntryDto('platform.currency.created', self::SUBJECT, $currency->code()->value, null, $changes);
    }

    /**
     * @param  array<string, int|string|array{ar: string, en: string}|null>  $before  attributes() taken before the change
     * @param  list<string>  $changed
     */
    public static function updated(Currency $currency, array $before, array $changed): AuditEntryDto
    {
        $after = self::attributes($currency);
        $changes = AuditChanges::none();

        foreach ($changed as $attribute) {
            $changes->changed($attribute, $before[$attribute] ?? null, $after[$attribute] ?? null);
        }

        return new AuditEntryDto('platform.currency.updated', self::SUBJECT, $currency->code()->value, null, $changes);
    }
}
