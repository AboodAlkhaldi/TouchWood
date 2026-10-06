import { intlLocale } from '@/lib/digits';

/*
| A file's size as a person reads it, in the page's language and digits (frontend.md §1.8): "2.3 MB"
| on an English page, "٢٫٣ م.ب" on an Arabic one.
|
| Kilobytes of 1024, because that is what an operating system shows next to the same file, and a
| library that disagrees with the desktop it was dragged from is just confusing; one decimal place
| below ten, none above ("9.4 MB", "24 MB"). Written here, as every date and count is, rather than by
| the server: its "2.3 MB" read "MB 2.3", in Latin digits, on an Arabic page (the owner's fix list,
| 2026-10-04).
*/

const UNITS = ['kilobyte', 'megabyte', 'gigabyte', 'terabyte'] as const;

export function fileSize(bytes: number, locale: string): string {
    if (bytes < 1024) {
        return new Intl.NumberFormat(intlLocale(locale), { style: 'unit', unit: 'byte', unitDisplay: 'short' }).format(bytes);
    }

    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < UNITS.length - 1) {
        value /= 1024;
        unit++;
    }

    const decimals = value < 10 ? 1 : 0;

    return new Intl.NumberFormat(intlLocale(locale), {
        style: 'unit',
        unit: UNITS[unit],
        unitDisplay: 'short',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}
