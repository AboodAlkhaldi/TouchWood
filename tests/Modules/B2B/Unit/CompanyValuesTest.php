<?php

declare(strict_types=1);

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyText;
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
        expect(RegistrationNumber::of('cr_number', 'CR-7001234567')->value)->toBe('CR-7001234567')
            ->and(RegistrationNumber::of('cr_number', 'س ت-1010123456')->value)->toBe('س ت-1010123456');
    });

    it('saves digits typed on an Arabic or Persian keyboard in Latin, in numbers and addresses (amendment 29)', function () {
        expect(RegistrationNumber::of('tax_number', '٣٠٠١٢٣٤٥٦٧٠٠٠٠٣')->value)->toBe('300123456700003')
            ->and(RegistrationNumber::of('cr_number', 'س ت-۱۰۱۰۱۲۳۴۵۶')->value)->toBe('س ت-1010123456')
            ->and(CompanyAddress::of("طريق الملك فهد ٧\nالرياض ١٢٣٤٥")->value)->toBe("طريق الملك فهد 7\nالرياض 12345")
            ->and(CompanyAddress::saved('01J8Z3K4M5N6P7Q8R9S0T1V2W3', 'الرياض ١٢٣٤٥')->value)->toBe('الرياض 12345')
            // A name is kept as typed: the owner's rule covers identifiers and addresses.
            ->and(CompanyName::of('مؤسسة ٢١')->value)->toBe('مؤسسة ٢١');
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
        'an address one character too long' => [fn () => CompanyAddress::of(str_repeat('a', CompanyAddress::MAX + 1)), ['address', 'at most 6000 characters']],
        // In the middle: one at the end is trimmed away, like a pasted trailing newline.
        'a NUL in a reason' => [fn () => Remark::of('reason', "Miss\0ing"), ['reason', 'without control characters']],
        'a note one character too long' => [fn () => Remark::of('note', str_repeat('a', Remark::MAX + 1)), ['note', 'at most 1000 characters']],
        'bytes that are not text' => [fn () => Remark::of('reason', "Missing \xC3\x28"), ['reason', 'text']],
    ]);

    it('keeps a picked address as its store wrote it, up to 6,000 characters, and which saved address it was (amendment 16(f))', function () {
        $formatted = "7 King Fahd Road\r\nAl Olaya\nRiyadh";
        $picked = CompanyAddress::saved('01J9ZC8Q0V4K6M2N8P0R2T4V6X', $formatted);

        expect($picked->value)->toBe("7 King Fahd Road\nAl Olaya\nRiyadh")
            ->and($picked->addressId)->toBe('01j9zc8q0v4k6m2n8p0r2t4v6x')
            ->and(mb_strlen(CompanyAddress::saved('01j9zc8q0v4k6m2n8p0r2t4v6x', str_repeat('ش', CompanyAddress::MAX))->value))->toBe(6000)
            // The placeholder anonymizing leaves has no saved address behind it.
            ->and(CompanyAddress::of('Deleted')->addressId)->toBeNull();
    });

    it('trims exactly what the page\'s trim() does, and nothing more (amendment 17(a))', function (string $given, string $kept) {
        expect(CompanyText::trimmed($given))->toBe($kept);
    })->with([
        'spaces, tabs and line breaks' => [" \t\n\r 12345 \r\n\t ", '12345'],
        'a vertical tab and a form feed' => ["\x0B\f12345\f\x0B", '12345'],
        'a no-break space' => ["12345\u{00A0}", '12345'],
        'an ideographic space' => ["\u{3000}12345", '12345'],
        'a byte-order mark' => ["\u{FEFF}12345", '12345'],
        'a line and a paragraph separator' => ["12345\u{2028}\u{2029}", '12345'],
        'a NUL is not a space: it stays, and is refused' => ["12345\0", "12345\0"],
        'what is between is kept' => ["King\u{00A0}Fahd  Road", "King\u{00A0}Fahd  Road"],
        'not text at all is given back as it came' => ["12345\xC3\x28 ", "12345\xC3\x28 "],
    ]);

    it('takes a number pasted with an invisible space at its end, and refuses one ending in a NUL', function () {
        expect(RegistrationNumber::of('cr_number', "1010123456\u{00A0}")->value)->toBe('1010123456')
            ->and(companyValueRefusal(fn () => RegistrationNumber::of('cr_number', "1010123456\0")))->toBe(['cr_number', 'on one line, without control characters']);
    });

    it('counts another saved address that reads the same as another pick', function () {
        $first = CompanyAddress::saved('01j9zc8q0v4k6m2n8p0r2t4v6x', 'Riyadh');

        expect($first->equals(CompanyAddress::saved('01J9ZC8Q0V4K6M2N8P0R2T4V6X', 'Riyadh')))->toBeTrue()
            ->and($first->equals(CompanyAddress::saved('01j9zc8q0v4k6m2n8p0r2t4v6y', 'Riyadh')))->toBeFalse()
            ->and($first->equals(CompanyAddress::saved('01j9zc8q0v4k6m2n8p0r2t4v6x', 'Jeddah')))->toBeFalse()
            ->and($first->equals(CompanyAddress::of('Riyadh')))->toBeFalse();
    });

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
