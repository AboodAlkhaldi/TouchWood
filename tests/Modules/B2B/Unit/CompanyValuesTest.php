<?php

declare(strict_types=1);

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;

/*
| What a company may type (b2b.md §1.1, amendments 2 and 3).
*/

/**
 * The refusal a value object gives, as [attribute, reason], or null when it accepts.
 *
 * Named for this file: a function declared in a Pest file is global to the whole suite.
 *
 * @return array{0: string, 1: string}|null
 */
function companyValueRefusal(Closure $make): ?array
{
    try {
        $make();
    } catch (InvalidCompanyAttribute $error) {
        return [$error->attribute, $error->reason];
    }

    return null;
}

describe('one-line values', function () {
    it('takes a name, a number and an "Other" type as they are meant, trimmed', function () {
        expect(CompanyName::of("  Al Noor Trading Est.\n")->value)->toBe('Al Noor Trading Est.')
            ->and(RegistrationNumber::of('cr_number', ' 1010-123 456 ')->value)->toBe('1010-123 456')
            ->and(CompanyTypeChoice::other(' Cooperative ')->other)->toBe('Cooperative');
    });

    it('takes a number in any script: the checking is loose, and staff read the certificate', function () {
        expect(RegistrationNumber::of('tax_number', '٣٠٠١٢٣٤٥٦٧٠٠٠٠٣')->value)->toBe('٣٠٠١٢٣٤٥٦٧٠٠٠٠٣')
            ->and(RegistrationNumber::of('cr_number', 'CR-7001234567')->value)->toBe('CR-7001234567');
    });

    it('refuses what does not belong on one line or in a number', function (Closure $make, array $refusal) {
        expect(companyValueRefusal($make))->toBe($refusal);
    })->with([
        'no name' => [fn () => CompanyName::of('   '), ['name', 'required']],
        'a name on two lines' => [fn () => CompanyName::of("Al Noor\nTrading"), ['name', 'on one line, without control characters']],
        'a name one character too long' => [fn () => CompanyName::of(str_repeat('a', CompanyName::MAX + 1)), ['name', 'at most 200 characters']],
        'a number with a slash' => [fn () => RegistrationNumber::of('cr_number', '1010/123'), ['cr_number', 'letters, digits, spaces and dashes only']],
        'a number with a hash' => [fn () => RegistrationNumber::of('tax_number', '#300123'), ['tax_number', 'letters, digits, spaces and dashes only']],
        'a number one character too long' => [fn () => RegistrationNumber::of('cr_number', str_repeat('1', RegistrationNumber::MAX + 1)), ['cr_number', 'at most 50 characters']],
        'an empty "Other"' => [fn () => CompanyTypeChoice::other(' '), ['company_type_other', 'required']],
        'an "Other" one character too long' => [fn () => CompanyTypeChoice::other(str_repeat('a', CompanyTypeChoice::OTHER_MAX + 1)), ['company_type_other', 'at most 100 characters']],
    ]);

    it('takes a value of exactly each limit', function () {
        expect(mb_strlen(CompanyName::of(str_repeat('ش', CompanyName::MAX))->value))->toBe(CompanyName::MAX)
            ->and(mb_strlen(RegistrationNumber::of('cr_number', str_repeat('1', RegistrationNumber::MAX))->value))->toBe(RegistrationNumber::MAX)
            ->and(mb_strlen((string) CompanyTypeChoice::other(str_repeat('a', CompanyTypeChoice::OTHER_MAX))->other))->toBe(CompanyTypeChoice::OTHER_MAX);
    });
});

describe('values written in lines', function () {
    it('keeps line breaks in an address and a remark, written as \n whatever the browser sent', function () {
        expect(CompanyAddress::of("King Fahd Road\r\nRiyadh 12345\rSaudi Arabia")->value)->toBe("King Fahd Road\nRiyadh 12345\nSaudi Arabia")
            ->and(Remark::of('reason', "The tax certificate is missing.\nThe CR number does not match.")->value)->toBe("The tax certificate is missing.\nThe CR number does not match.");
    });

    it('refuses any other control character, and an empty or overlong text', function (Closure $make, array $refusal) {
        expect(companyValueRefusal($make))->toBe($refusal);
    })->with([
        'no address' => [fn () => CompanyAddress::of("\n \n"), ['address', 'required']],
        'a tab in an address' => [fn () => CompanyAddress::of("King Fahd Road\tRiyadh"), ['address', 'without control characters']],
        'an address one character too long' => [fn () => CompanyAddress::of(str_repeat('a', CompanyAddress::MAX + 1)), ['address', 'at most 500 characters']],
        // In the middle: one at the end is trimmed away, like a pasted trailing newline.
        'a NUL in a reason' => [fn () => Remark::of('reason', "Miss\0ing"), ['reason', 'without control characters']],
        'a note one character too long' => [fn () => Remark::of('note', str_repeat('a', Remark::MAX + 1)), ['note', 'at most 1000 characters']],
        'bytes that are not text' => [fn () => Remark::of('reason', "Missing \xC3\x28"), ['reason', 'text']],
    ]);

    it('takes a remark of exactly 1000 characters, line breaks counted', function () {
        $text = str_repeat("ab\n", 333).'a';

        expect(mb_strlen(Remark::of('reason', $text)->value))->toBe(Remark::MAX);
    });
});

describe('the company type', function () {
    it('is a listed type or the company\'s own words, never both', function () {
        $listed = CompanyTypeChoice::listed('01J8Z3K4M5N6P7Q8R9S0T1V2W3');
        $other = CompanyTypeChoice::other('Cooperative');

        expect([$listed->typeId, $listed->other, $listed->isOther()])->toBe(['01j8z3k4m5n6p7q8r9s0t1v2w3', null, false])
            ->and([$other->typeId, $other->other, $other->isOther()])->toBe([null, 'Cooperative', true])
            ->and($listed->equals(CompanyTypeChoice::listed('01j8z3k4m5n6p7q8r9s0t1v2w3')))->toBeTrue()
            ->and($listed->equals($other))->toBeFalse();
    });
});
