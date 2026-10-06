/*
| "Every number input accepts both kinds of digits", and every number is saved and shown in Latin
| (frontend.md §1.8; the owner, 2026-10-06).
|
| An Arabic keyboard types ٠١٢٣٤٥٦٧٨٩, and a person entering ٠٥٠١٢٣٤٥٦٧ has entered the same number
| as 0501234567. The difference is turned back here, as the person types - a code is compared as a
| hash and a phone number is matched against E.164 - and the server turns it again for the text
| that identifies or locates something (Shared\Domain\Text\LatinDigits).
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
| The language tag for Intl - dates, times, numbers - on a page in `locale`: Arabic words, Latin
| digits (the owner, 2026-10-06: "no any arabic numbers across the whole system"; frontend.md §1.8).
| Said outright with `-u-nu-latn` rather than left to plain "ar": which digits "ar" means is CLDR's
| choice (CLDR 46 made it Latin; older engines still write ١٢٣), and the rule is ours.
*/
export function intlLocale(locale: string): string {
    return locale === 'ar' ? 'ar-u-nu-latn' : 'en';
}

/** A whole number in Latin digits, grouped as the page's language groups it (frontend.md §1.8). */
export function figure(locale: string, value: number): string {
    return new Intl.NumberFormat(intlLocale(locale)).format(value);
}
