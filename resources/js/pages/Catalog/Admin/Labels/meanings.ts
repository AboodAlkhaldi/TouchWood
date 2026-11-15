import type { Tone } from '@/lib/tones';

/*
| A label's look in Geist's meanings (catalog.md amendment 1(e)), shared by the labels' list and its dialog.
*/

export const MEANINGS = [
    ['neutral', 'gray'],
    ['information', 'blue'],
    ['healthy', 'green'],
    ['warning', 'amber'],
    ['error', 'red'],
] as const;

export type Meaning = (typeof MEANINGS)[number][0];

export function toneOf(meaning: Meaning, subtle: boolean): Tone {
    const colour = MEANINGS.find(([name]) => name === meaning)?.[1] ?? 'gray';

    return (subtle ? `${colour}-subtle` : colour) as Tone;
}

export function meaningOf(labelTone: string): { meaning: Meaning; subtle: boolean } {
    const subtle = labelTone.endsWith('-subtle');
    const colour = labelTone.replace('-subtle', '');

    return { meaning: MEANINGS.find(([, each]) => each === colour)?.[0] ?? 'neutral', subtle };
}
