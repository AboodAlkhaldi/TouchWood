/*
| "Every number input accepts both kinds of digits" (frontend.md §1.8).
|
| An Arabic keyboard types ٠١٢٣٤٥٦٧٨٩, and a person entering ٠٥٠١٢٣٤٥٦٧ has entered the same number
| as 0501234567. The server never sees the difference, because the difference is turned back here,
| where the person typed it - a code is compared as a hash and a phone number is matched against
| E.164, and neither knows what an Arabic-Indic digit is.
|
| Both ranges are converted: Arabic-Indic (U+0660) and the Extended Arabic-Indic (U+06F0) that
| Persian and Urdu keyboards produce, which look the same to a reader and are not the same
| characters.
*/

const ARABIC_INDIC = 0x0660;
const EXTENDED_ARABIC_INDIC = 0x06f0;

export function toLatinDigits(value: string): string {
    let out = '';

    for (const character of value) {
        const code = character.codePointAt(0) ?? 0;

        if (code >= ARABIC_INDIC && code <= ARABIC_INDIC + 9) {
            out += String(code - ARABIC_INDIC);

            continue;
        }

        if (code >= EXTENDED_ARABIC_INDIC && code <= EXTENDED_ARABIC_INDIC + 9) {
            out += String(code - EXTENDED_ARABIC_INDIC);

            continue;
        }

        out += character;
    }

    return out;
}

/*
| The language tag for Intl - dates, times, numbers - on a page in `locale`: an Arabic page shows
| Arabic-Indic digits (§1.8), and says so to Intl, since plain "ar" now formats in Latin digits
| (CLDR 46 made Latin the Arabic default; checked 2026-10-04 with ICU 77 / CLDR 47: "123", while
| "ar-u-nu-arab" gives "١٢٣"). Codes and phone numbers are never formatted through Intl.
*/
export function intlLocale(locale: string): string {
    return locale === 'ar' ? 'ar-u-nu-arab' : 'en';
}

/** A whole number in the page's digits (frontend.md §1.8): Arabic-Indic on an Arabic page. */
export function figure(locale: string, value: number): string {
    return new Intl.NumberFormat(intlLocale(locale)).format(value);
}
