/*
| Geist's Badge colours (frontend.md §1.10, §1.11), as classes for shadcn's Badge.
|
| shadcn's Badge keeps its own code: the colours are given at the point of use, through
| `className`, never by adding variants to it (§1.11 allows five edits to shadcn's code, and this
| is not one of them). Geist's rules: colour carries meaning - green healthy, red error, amber
| warning, blue information, grey neutral - and never alone: the word is always there. `-subtle`
| tones it down on dense rows.
*/

export type Tone =
    | 'gray'
    | 'blue'
    | 'green'
    | 'amber'
    | 'red'
    | 'gray-subtle'
    | 'blue-subtle'
    | 'green-subtle'
    | 'amber-subtle'
    | 'red-subtle';

const TONE: Record<Tone, string> = {
    gray: 'bg-ink-muted text-surface',
    blue: 'bg-info text-ink-on-brand',
    green: 'bg-good text-ink-on-brand',
    amber: 'bg-warn text-ink-on-brand',
    red: 'bg-bad text-ink-on-brand',
    'gray-subtle': 'bg-surface-sunken text-ink-muted',
    'blue-subtle': 'bg-info-soft text-info',
    'green-subtle': 'bg-good-soft text-good',
    'amber-subtle': 'bg-warn-soft text-warn',
    'red-subtle': 'bg-bad-soft text-bad',
};

/** Geist's medium badge: 24 px high, label-12 type. */
const SIZE = 'h-6 px-2.5 text-label-12';

/** The classes that give shadcn's Badge one of Geist's colours. */
export function tone(name: Tone): string {
    return `${TONE[name]} ${SIZE} border-transparent`;
}
