<?php

declare(strict_types=1);

/*
| What the audit log calls each B2B action (Presentation/lang/{ar,en}/audit.php): the company's own
| three (step 3b) and staff's (step 4, amendment 10). An action with no name shows its raw key, so
| every one B2B records is named in both languages.
*/

/**
 * Every action B2B writes to the audit log.
 *
 * @return list<string>
 */
function b2bAuditNamesActions(): array
{
    $types = [];

    foreach (['company_type', 'document_type'] as $subject) {
        foreach (['added', 'renamed', 'moved', 'deactivated', 'activated'] as $what) {
            $types[] = "b2b.{$subject}.{$what}";
        }
    }

    return [
        'b2b.application.submitted', 'b2b.application.discarded', 'b2b.company.address_changed',
        'b2b.application.approved', 'b2b.application.rejected',
        'b2b.company.suspended', 'b2b.company.reinstated',
        'b2b.company.type_corrected', 'b2b.company.type_replaced', 'b2b.company.document_opened',
        ...$types,
        'b2b.document_type.requirement_changed',
        'b2b.type_lists.reviewed',
    ];
}

it('names every action B2B records, in Arabic and English', function (string $action) {
    $key = 'b2b::audit.'.substr($action, strlen('b2b.'));

    foreach (['ar', 'en'] as $locale) {
        $name = trans($key, [], $locale);

        expect(is_string($name) && $name !== '' && $name !== $key)->toBeTrue("{$action} has no {$locale} name");
    }
})->with(b2bAuditNamesActions());

it('lists every action the code records, so a new one cannot go unnamed', function () {
    $recorded = [];

    // GLOB_BRACE is not available on every platform, so each folder is listed.
    $files = array_merge(
        glob(dirname(__DIR__, 4).'/src/Modules/B2B/Application/Audit/*.php') ?: [],
        glob(dirname(__DIR__, 4).'/src/Modules/B2B/Application/Staff/*.php') ?: [],
    );

    foreach ($files as $file) {
        preg_match_all("/'(b2b\\.[a-z_]+\\.[a-z_]+)'/", (string) file_get_contents($file), $matches);
        array_push($recorded, ...$matches[1]);
    }

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/B2B/Application/Command/*/*Handler.php') ?: [] as $file) {
        preg_match_all("/'(b2b\\.company\\.[a-z_]+)'/", (string) file_get_contents($file), $matches);
        array_push($recorded, ...$matches[1]);
    }

    // A moved folder must not make this pass over nothing.
    expect($recorded)->not->toBeEmpty()
        ->and(array_values(array_diff(array_unique($recorded), b2bAuditNamesActions(), ['b2b.company', 'b2b.application', 'b2b.type_lists'])))->toBe([]);
});
