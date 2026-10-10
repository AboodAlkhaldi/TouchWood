/*
| The rules every box checks as it is typed (resources/js/lib/checks.ts; frontend.md §1.7). Run by
| tests/Frontend/run.mjs, inside the PHP suite (tests/Architecture/FrontendUnitTest.php).
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { problemOf, type Rules, sentenceOf, subjectOf } from '@/lib/checks';

/** The rule a value breaks, or null. */
function broken(value: string, rules: Rules): string | null {
    return problemOf(value, rules)?.rule ?? null;
}

describe('an empty box', () => {
    it('is wrong only when it is required', () => {
        assert.equal(broken('', {}), null);
        assert.equal(broken('', { required: true }), 'required');
    });

    it('counts spaces alone as empty', () => {
        assert.equal(broken('   ', { required: true }), 'required');
    });

    it('says nothing else about a value that is not there', () => {
        assert.equal(broken('', { number: { min: 1, max: 600 } }), null);
        assert.equal(broken('', { email: true, length: { min: 2 } }), null);
    });
});

describe('a number box', () => {
    const months: Rules = { required: true, number: { min: 1, max: 600 } };

    it('refuses a letter the moment it is typed', () => {
        assert.equal(broken('a', months), 'number');
        assert.equal(broken('12a', months), 'number');
        assert.equal(broken('1 2', months), 'number');
    });

    it('refuses what only looks like a number', () => {
        for (const value of ['1e5', '+5', '1,000', '.5', '5.', '--5']) {
            assert.equal(broken(value, months), 'number', value);
        }
    });

    it('reads Latin digits only: a typed Arabic-Indic one is turned into 0-9 before (lib/digits.ts)', () => {
        assert.equal(broken(String.fromCharCode(0x0665), months), 'number');
    });

    it('takes a whole number inside its range, the ends included', () => {
        for (const value of ['1', '600', '42', ' 7 ']) {
            assert.equal(broken(value, months), null, value);
        }
    });

    it('says the range when outside it', () => {
        assert.deepEqual(problemOf('0', months), { rule: 'range', values: { min: 1, max: 600 } });
        assert.deepEqual(problemOf('601', months), { rule: 'range', values: { min: 1, max: 600 } });
        assert.deepEqual(problemOf('-3', months), { rule: 'range', values: { min: 1, max: 600 } });
    });

    it('says the one end it has', () => {
        assert.deepEqual(problemOf('0', { number: { min: 1 } }), { rule: 'at_least', values: { min: 1 } });
        assert.deepEqual(problemOf('11', { number: { max: 10 } }), { rule: 'at_most', values: { max: 10 } });
        assert.equal(broken('-99999', { number: { max: 10 } }), null);
        assert.equal(broken('99999', { number: {} }), null);
    });

    it('takes no decimals unless it allows them', () => {
        assert.equal(broken('1.5', months), 'whole');
        assert.equal(broken('1.5', { number: { decimals: 1 } }), null);
        assert.deepEqual(problemOf('1.55', { number: { decimals: 1 } }), { rule: 'decimals_one', values: { count: 1 } });
        assert.deepEqual(problemOf('1.555', { number: { decimals: 2 } }), { rule: 'decimals', values: { count: 2 } });
        assert.equal(broken('15.25', { number: { decimals: 2, min: 0, max: 100 } }), null);
    });

    it('checks the decimals before the range', () => {
        assert.equal(broken('700.5', months), 'whole');
    });

    it('is not held to a length', () => {
        assert.equal(broken('123456', { number: {}, length: { max: 2 } }), null);
    });
});

describe('a code of digits', () => {
    const code: Rules = { required: true, digits: true, length: { min: 2, max: 10 } };

    it('refuses anything but 0-9', () => {
        assert.equal(broken('12a', code), 'digits');
        assert.equal(broken('-12', code), 'digits');
        assert.equal(broken('1.2', code), 'digits');
    });

    it('counts its length in digits', () => {
        assert.deepEqual(problemOf('1', code), { rule: 'min_digits', values: { min: 2 } });
        assert.deepEqual(problemOf('12345678901', code), { rule: 'max_digits', values: { max: 10 } });
        assert.equal(broken('0012345678', code), null);
    });
});

describe('a code of letters', () => {
    const code: Rules = { required: true, letters: true, length: { min: 3, max: 3 } };

    it('refuses anything but A-Z, either case', () => {
        assert.equal(broken('SA1', code), 'letters');
        assert.equal(broken('S-R', code), 'letters');
        assert.equal(broken('رسس', code), 'letters');
        assert.equal(broken('sar', code), null);
    });

    it('then counts its letters', () => {
        assert.deepEqual(problemOf('SA', code), { rule: 'min_length', values: { min: 3 } });
        assert.deepEqual(problemOf('SARR', code), { rule: 'max_length', values: { max: 3 } });
    });
});

describe('a module\'s own format', () => {
    const swatch: Rules = { required: true, format: { pattern: /^#[0-9a-f]{6}$/i, key: 'catalog::admin_attributes.check.swatch' } };

    it('names its own sentence when the value does not fit', () => {
        assert.deepEqual(problemOf('#12345', swatch), { rule: 'format', values: {}, key: 'catalog::admin_attributes.check.swatch' });
        assert.equal(broken('#1A2b3C', swatch), null);
    });

    it('is checked after the length', () => {
        assert.equal(broken('#12345z', { ...swatch, length: { max: 7 } }), 'format');
        assert.equal(broken('#12345zz', { ...swatch, length: { max: 7 } }), 'max_length');
    });
});

describe('a length', () => {
    it('is counted after the ends are trimmed', () => {
        assert.equal(broken('  ab  ', { length: { max: 2 } }), null);
        assert.deepEqual(problemOf('abc', { length: { max: 2 } }), { rule: 'max_length', values: { max: 2 } });
        assert.deepEqual(problemOf(' a ', { length: { min: 2 } }), { rule: 'min_length', values: { min: 2 } });
    });

    it('counts characters, not the units JavaScript stores them in', () => {
        // Three emoji are three characters to PHP's mb_strlen, and six UTF-16 units.
        const three = String.fromCodePoint(0x1f600, 0x1f600, 0x1f600);

        assert.equal(three.length, 6);
        assert.equal(broken(three, { length: { max: 3 } }), null);
        assert.equal(broken(`${three}x`, { length: { max: 3 } }), 'max_length');
    });

    it('says "one character" for a box that takes one', () => {
        assert.equal(broken('$', { length: { max: 1 } }), null);
        assert.equal(broken(String.fromCodePoint(0x1f4b0), { length: { max: 1 } }), null);
        assert.deepEqual(problemOf('$$', { length: { max: 1 } }), { rule: 'max_length_one', values: {} });
    });

    it('is counted the way the server counts it, when the box says how', () => {
        // A description's marks are not its text: "**" and "# " are not counted.
        const marks = (text: string) => text.replace(/\*\*|^# /gm, '').length;

        assert.equal(broken('# **abc**', { length: { max: 3, of: marks } }), null);
        assert.equal(broken('# **abcd**', { length: { max: 3, of: marks } }), 'max_length');
    });
});

describe('an email address', () => {
    it('needs something, an @ and something, with no spaces', () => {
        assert.equal(broken('name', { email: true }), 'email');
        assert.equal(broken('name@', { email: true }), 'email');
        assert.equal(broken('@example.com', { email: true }), 'email');
        assert.equal(broken('na me@example.com', { email: true }), 'email');
    });

    it('leaves the rest to the server', () => {
        assert.equal(broken('name@example.com', { email: true }), null);
        assert.equal(broken('name@example', { email: true }), null);
    });

    it('is checked after its length', () => {
        assert.equal(broken('a@b', { email: true, length: { max: 2 } }), 'max_length');
    });
});

describe('a phone number', () => {
    it('takes a number with its country code, as Access reads one', () => {
        for (const value of ['+966501234567', '00966 50 123 4567', '+966 (50) 123-4567', '+966.50.123.4567', '+1234567', '+123456789012345']) {
            assert.equal(broken(value, { phone: true }), null, value);
        }
    });

    it('reads Arabic-Indic digits as the digits they are', () => {
        const typed = `+${[9, 6, 6, 5, 0, 1, 2, 3, 4, 5, 6, 7].map((digit) => String.fromCharCode(0x0660 + digit)).join('')}`;

        assert.equal(broken(typed, { phone: true }), null);
    });

    it('refuses a number without its country code, too short or too long, or with letters', () => {
        for (const value of ['0501234567', '+0501234567', '+123456', '+1234567890123456', '+96650123x567', '+966+501234567']) {
            assert.equal(broken(value, { phone: true }), 'phone', value);
        }
    });
});

describe('a password', () => {
    const password: Rules = { required: true, keepSpaces: true, length: { min: 12 } };

    it('counts the spaces at its ends, as the server does not trim one', () => {
        assert.equal(broken('  abcdefghij', password), null);
        assert.equal(broken('abcdefghij', password), 'min_length');
    });

    it('is not empty when it is only spaces', () => {
        assert.equal(broken(' ', { required: true, keepSpaces: true }), null);
        assert.equal(broken('', { required: true, keepSpaces: true }), 'required');
    });
});

describe('the sentence', () => {
    // The translator as t() is: the key's words with :name filled in.
    const words: Record<string, string> = {
        'ui.check.range': ':field is from :min to :max.',
        'ui.check.required': ':field is required.',
    };
    const t = (key: string, values: Record<string, string | number> = {}) =>
        Object.entries(values).reduce((line, [name, value]) => line.replace(`:${name}`, String(value)), words[key] ?? key);

    it('names the box and the rule', () => {
        assert.equal(sentenceOf({ rule: 'required', values: {} }, 'Email address', t, 'en'), 'Email address is required.');
    });

    it('is the module\'s own for its own format', () => {
        words['catalog::admin_attributes.check.swatch'] = ':field is a colour code like #1a2b3c.';

        assert.equal(sentenceOf({ rule: 'format', values: {}, key: 'catalog::admin_attributes.check.swatch' }, 'Swatch', t, 'en'), 'Swatch is a colour code like #1a2b3c.');
    });

    it('writes its figures grouped, in Latin digits on an Arabic page too', () => {
        const english = sentenceOf({ rule: 'range', values: { min: 1, max: 100000 } }, 'Retail minimum', t, 'en');
        const arabic = sentenceOf({ rule: 'range', values: { min: 1, max: 100000 } }, 'الحد الأدنى', t, 'ar');

        assert.equal(english, 'Retail minimum is from 1 to 100,000.');
        assert.match(arabic, /from 1 to 100.000\.$/u);
        assert.doesNotMatch(arabic, /[٠-٩۰-۹]/u);
    });
});

describe('the subject', () => {
    it('is the label in sentence case in English', () => {
        assert.equal(subjectOf('Email Address', 'en'), 'Email address');
        assert.equal(subjectOf('Commercial Registration Number', 'en'), 'Commercial registration number');
        assert.equal(subjectOf('Period', 'en'), 'Period');
    });

    it('keeps capitals that are not a title case', () => {
        assert.equal(subjectOf('SMS Code', 'en'), 'SMS code');
        assert.equal(subjectOf('Arabic Name', 'en'), 'Arabic name');
        assert.equal(subjectOf('Name in English', 'en'), 'Name in English');
    });

    it('is the label as it is in Arabic', () => {
        assert.equal(subjectOf('الاسم بالعربية', 'ar'), 'الاسم بالعربية');
    });
});
