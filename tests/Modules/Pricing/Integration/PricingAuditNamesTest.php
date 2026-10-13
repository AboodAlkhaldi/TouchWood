<?php

declare(strict_types=1);

/*
| What the audit log calls each Pricing action (Presentation/lang/{ar,en}/audit.php; pricing.md §3,
| §8 #14). An action with no name shows its raw key, so every one Pricing records is named in both
| languages.
*/

/**
 * Every action Pricing writes to the audit log, read from the code: each entry is built with its
 * action written out first.
 *
 * @return list<string>
 */
function pricingAuditNamesRecorded(): array
{
    $actions = [];
    $root = dirname(__DIR__, 4).'/src/Modules/Pricing';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        // Built directly, or through SaleAudit::entry with the action written out first, or handed to
        // RetailPriceWriter::set after the price's source.
        $code = (string) file_get_contents($file->getPathname());
        preg_match_all("/(?:new AuditEntryDto|SaleAudit::entry|DiscountAudit::entry)\\(\\s*'(pricing\\.[a-z_]+\\.[a-z_]+)'/", $code, $found);
        preg_match_all("/PriceSource::[A-Za-z]+,\\s*'(pricing\\.[a-z_]+\\.[a-z_]+)'/", $code, $written);
        array_push($actions, ...$found[1], ...$written[1]);
    }

    $actions = array_values(array_unique($actions));
    sort($actions);

    return $actions;
}

it('finds the actions Pricing records', function () {
    // A guard over files must find something, or it passes over nothing.
    expect(pricingAuditNamesRecorded())->toBe([
        'pricing.category_discount.added', 'pricing.category_discount.changed', 'pricing.category_discount.ended', 'pricing.category_discount.removed',
        'pricing.retail_price.filed', 'pricing.retail_price.removed', 'pricing.retail_price.set',
        'pricing.sale.added', 'pricing.sale.changed', 'pricing.sale.ended', 'pricing.sale.removed',
        'pricing.wholesale_prices.set',
    ]);
});

it('names every action Pricing records, in Arabic and English', function () {
    foreach (pricingAuditNamesRecorded() as $action) {
        $key = 'pricing::audit.'.substr($action, strlen('pricing.'));

        foreach (['ar', 'en'] as $locale) {
            $name = trans($key, [], $locale);

            expect(is_string($name) && $name !== '' && $name !== $key)->toBeTrue("{$action} has no {$locale} name");
        }
    }
});

it('names nothing the code does not record, the same in both languages', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Pricing/Presentation/lang/ar/audit.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Pricing/Presentation/lang/en/audit.php';
    $named = array_map(static fn (int|string $key): string => "pricing.{$key}", array_keys($en));
    sort($named);

    expect(array_keys($ar))->toBe(array_keys($en))
        ->and($named)->toBe(pricingAuditNamesRecorded());
});
