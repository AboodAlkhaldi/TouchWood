<?php

declare(strict_types=1);

/*
| Handoff §2.2. No country name, currency code, country code or store code in
| Domain/ or Application/. Everything is driven by the store row.
*/

/**
 * @return list<string>
 */
function storeLiteralsIn(string $code): array
{
    $patterns = [
        '/\b(SA|EG|AE|KSA|UAE|SAR|EGP|AED)\b/',
        '/\b(saudi|egypt|egyptian|emirates|emirati|riyadh|jeddah|cairo|dubai)\b/i',
        '/[\'"](sa|eg|ae|ksa|uae|sar|egp|aed)[\'"]/i',
    ];

    $found = [];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $code, $matches) > 0) {
            array_push($found, ...$matches[0]);
        }
    }

    return $found;
}

it('detects store literals', function (string $code) {
    expect(storeLiteralsIn($code))->not->toBeEmpty();
})->with([
    'currency code' => ['$currency = "SAR";'],
    'currency code in lowercase' => ['$currency = "sar";'],
    'currency code in mixed case' => ['$currency = "Sar";'],
    'another lowercase currency' => ["\$currency = 'egp';"],
    'country code' => ['if ($country === KSA) {}'],
    'lowercase country code' => ['$country = "ksa";'],
    'store code' => ["\$store = 'eg';"],
    'country name' => ['// only for Saudi customers'],
    'city in a timezone' => ["\$timezone = 'Asia/Riyadh';"],
]);

it('ignores ordinary code', function () {
    expect(storeLiteralsIn('$store->currency()->code(); $sale = $saleMode; $area = "east"; $locale = "ar"; $safe = "same";'))->toBeEmpty();
});

it('finds no store literals in Domain or Application', function () {
    $root = dirname(__DIR__, 2).'/src';
    // GLOB_BRACE is not available on every platform, so each pattern is listed.
    $dirs = array_merge(
        glob($root.'/Modules/*/Domain', GLOB_ONLYDIR) ?: [],
        glob($root.'/Modules/*/Application', GLOB_ONLYDIR) ?: [],
        glob($root.'/Shared/Domain', GLOB_ONLYDIR) ?: [],
        glob($root.'/Shared/Application', GLOB_ONLYDIR) ?: [],
    );

    $violations = [];
    $scanned = 0;

    foreach ($dirs as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;
            $literals = storeLiteralsIn((string) file_get_contents($file->getPathname()));

            if ($literals !== []) {
                $violations[] = $file->getPathname().': '.implode(', ', array_unique($literals));
            }
        }
    }

    // Guards against a moved directory making this test pass over nothing.
    expect($scanned)->toBeGreaterThan(20)
        ->and($violations)->toBe([]);
});
