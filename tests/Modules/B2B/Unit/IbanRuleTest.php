<?php

declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;
use Modules\B2B\Application\Settings\IbanRule;

/*
| The IBAN a store's companies transfer to (b2b.md amendment 12(b)): its shape and its check digits
| (ISO 13616). The valid numbers are published examples, not accounts; the "ZZ" ones are made to
| pass the check digits, so their shape is the only thing that can refuse them.
*/

/**
 * What the rule says of a value: null when it accepts, the refusal when it does not.
 *
 * Named for this file: a function declared in a Pest file is global to the whole suite.
 */
function ibanRuleRefusal(mixed $value): ?string
{
    $refusal = null;

    (new IbanRule)->validate('value', $value, function (string $message) use (&$refusal): PotentiallyTranslatedString {
        $refusal = $message;

        return new PotentiallyTranslatedString($message, new Translator(new ArrayLoader, 'en'));
    });

    return $refusal;
}

it('accepts an IBAN whose check digits are right, with or without its spaces, in either case', function (string $iban) {
    expect(IbanRule::valid($iban))->toBeTrue()
        ->and(ibanRuleRefusal($iban))->toBeNull();
})->with([
    'grouped in fours' => 'GB82 WEST 1234 5698 7654 32',
    'written together' => 'DE89370400440532013000',
    'in small letters' => 'gb82 west 1234 5698 7654 32',
    'the shortest, 15' => 'NO9386011117947',
    'a long one, 32' => 'LC55HEMM000100010012001200023015',
    'the longest, 34' => 'ZZ64AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
]);

it('refuses one wrong digit: the check digits catch it', function () {
    expect(IbanRule::valid('GB82 WEST 1234 5698 7654 33'))->toBeFalse()
        ->and(IbanRule::valid('GB83 WEST 1234 5698 7654 32'))->toBeFalse()
        ->and(ibanRuleRefusal('GB82 WEST 1234 5698 7654 33'))->toContain('check digits');
});

it('refuses the wrong shape, even with right check digits', function (string $iban) {
    expect(IbanRule::valid($iban))->toBeFalse();
})->with([
    'too short, 14' => 'ZZ121234567890',
    'too long, 35' => 'ZZ81AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    'a sign inside' => 'GB82-WEST-1234-5698-7654-32',
    'a line break inside' => "GB82WEST1234\n5698765432",
    'digits where the letters go' => '120010000000001',
    'letters where the check digits go' => 'ZZAB10000000008',
    'nothing but spaces' => '    ',
]);

it('refuses a value that is not text', function () {
    expect(ibanRuleRefusal(8237040044))->not->toBeNull()
        ->and(ibanRuleRefusal(null))->not->toBeNull();
});
