/*
| Putting a left-to-right value inside a right-to-left sentence (frontend.md §1.8).
|
| An Arabic sentence with a date, an address, an id or a number in it is laid out by the browser as
| one run of mixed text, and the neutral characters between them - hyphens, dots, spaces - belong to
| whichever side wins. So "منذ 2026-09-24" comes out as "منذ 24-09-2026": the digits never moved,
| but the browser read the hyphens the other way round and swapped the pieces (found by looking at
| the staff list, 2026-09-24).
|
| The fix is to say where the value starts and ends. U+2068 opens an isolate whose direction is
| worked out from the text itself, and U+2069 closes it; the characters are invisible, and what is
| inside them is laid out on its own and then placed as a single unit.
|
| Use this for a value that goes **into a translated sentence**, where there is no element to hang
| dir="ltr" on. Where the value has its own element, dir="ltr" on that element says the same thing
| and is easier to read.
*/

/** Opens an isolate whose direction is taken from its own first strong character. */
const FIRST_STRONG_ISOLATE = '⁨';

/** And closes it. */
const POP_DIRECTIONAL_ISOLATE = '⁩';

export function isolate(value: string | number): string {
    return FIRST_STRONG_ISOLATE + String(value) + POP_DIRECTIONAL_ISOLATE;
}
