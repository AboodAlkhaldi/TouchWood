/*
| What the Boxes screen and its dialogs share (shipping.md §4.1 part 3): the words' prefix, the address, and the
| units - sizes in whole mm (B5), the most weight typed and shown in kg with up to three decimals, kept in grams
| (B4) - and what fits after the padding (B6). Pure functions, no React.
*/

export const B = 'shipping::admin_boxes';

export const BOXES = '/admin/shipping/boxes';

/** The most a size or a weight in grams may be (§9.8: 1–1,000,000). */
export const MOST = 1_000_000;

/** A whole number of mm as typed, or null when it is not one from 1 to 1,000,000. */
export function mmOf(text: string): number | null {
    const value = text.trim();

    if (!/^\d{1,7}$/.test(value)) {
        return null;
    }

    const mm = Number(value);

    return mm >= 1 && mm <= MOST ? mm : null;
}

/** A weight typed in kg, up to three decimals, in grams - or null when it is not one from 1 g to 1,000 kg. */
export function gramsOf(text: string): number | null {
    const value = text.trim();
    const match = /^(\d{1,4})(?:\.(\d{1,3}))?$/.exec(value);

    if (match === null) {
        return null;
    }

    const grams = Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0') || '0');

    return grams >= 1 && grams <= MOST ? grams : null;
}

/** Grams as kg, as a person types them: 15000 → "15", 2500 → "2.5", 1 → "0.001". */
export function kgOf(grams: number): string {
    const whole = Math.floor(grams / 1000);
    const rest = String(grams % 1000).padStart(3, '0').replace(/0+$/, '');

    return rest === '' ? String(whole) : `${whole}.${rest}`;
}

/** The inner sizes less the padding on both sides (§1.7), or null when a side cannot take the padding. */
export function inside(sizes: [number, number, number], paddingMm: number): [number, number, number] | null {
    const left = sizes.map((side) => side - 2 * paddingMm) as [number, number, number];

    return left.every((side) => side > 0) ? left : null;
}
