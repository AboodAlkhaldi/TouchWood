/*
| A moment typed in a store's own clock (pricing.md §4.3.1 "Moments", Q4): a date and a 24-hour time,
| as the store's zone reads them, sent as ISO 8601 with the store's offset that day ("2026-10-20T14:30:00
| +03:00"), so the server reads the instant meant whatever clock the person's own device keeps.
|
| A start offers **Now** - the default, which sends no start - and an end **No End**; a typed start in
| the past is refused as it is typed ("Start must be now or later."), an end not after its start too
| ("End must be after the start."). A start that passes while the form is open is not refused: the
| server takes it as now, and the field says so ("Starts when saved if this time has passed.") - so the
| check compares with the minute it was typed in, not with every minute after.
|
| Pure functions only, for components/DateTimeField.tsx and its form's checks; tested in
| tests/Frontend/moments.test.ts.
*/

import { intlLocale } from '@/lib/digits';
import type { Box } from '@/lib/use-checks';

/** What the person typed: Now (or No End), else a date and a time, and when they last typed them. */
export type MomentInput = {
    /** Now for a start, No End for an end: nothing is sent. */
    none: boolean;
    /** YYYY-MM-DD, the store's own date; '' until chosen. */
    date: string;
    /** HH:MM as typed, 24-hour; checked, never corrected. */
    time: string;
    /** When the date or the time was last changed (milliseconds): a past moment is judged against it. */
    typedAt: number;
    /**
     * The moment as it was saved, "YYYY-MM-DD HH:MM", when the form opened on one (`momentFrom`): a
     * running offer's start has passed, and is not refused while it is left as it was.
     */
    saved?: string;
};

export type MomentKind = 'start' | 'end';

/** The words a moment's check can say, each taking :field (lang/{ar,en}/ui.php, "check"). */
export const MOMENT_WORDS = {
    date: 'ui.check.date',
    time: 'ui.check.time',
    notPast: 'ui.check.not_past',
    afterStart: 'ui.check.after_start',
} as const;

const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;
export const TIME = /^([01]\d|2[0-3]):([0-5]\d)$/;
const MINUTE = 60_000;

/** A start at Now, or an end with No End: what a new form holds. */
export function noMoment(): MomentInput {
    return { none: true, date: '', time: '', typedAt: 0 };
}

/** The wall clock of a zone at an instant: its date and its time to the minute. */
export function wallClock(zone: string, at: number): { date: string; time: string } {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat(intlLocale('en'), { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(new Date(at))
            .map((part) => [part.type, part.value]),
    );

    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
}

/** How far a zone's clock is ahead of UTC at an instant, in minutes (negative behind). */
export function offsetMinutes(zone: string, at: number): number {
    const wall = wallClock(zone, at);
    const asUtc = Date.UTC(...dayParts(wall.date), ...timeParts(wall.time));
    const minute = Math.floor(at / MINUTE) * MINUTE;

    return Math.round((asUtc - minute) / MINUTE);
}

/** The instant a date and time name in a zone, or null when either is not one. */
export function instantOf(date: string, time: string, zone: string): number | null {
    if (!isDay(date) || !TIME.test(time)) {
        return null;
    }

    const guess = Date.UTC(...dayParts(date), ...timeParts(time));
    const first = guess - offsetMinutes(zone, guess) * MINUTE;
    // Across a change of the clocks the offset differs either side: take the one in force then.
    const second = guess - offsetMinutes(zone, first) * MINUTE;

    return second;
}

/** The moment as sent: ISO 8601 with the zone's offset at it, or null for Now and No End (or nothing yet). */
export function isoOf(input: MomentInput, zone: string): string | null {
    if (input.none) {
        return null;
    }

    const at = instantOf(input.date, input.time.trim(), zone);

    if (at === null) {
        return null;
    }

    // Written from the instant itself, as the zone's clock reads it then: a time the clocks skip
    // (Cairo's 00:30 on the night they jump forward) is sent as the instant checked, never as a wall
    // time beside an offset that does not belong to it.
    const wall = wallClock(zone, at);
    const offset = offsetMinutes(zone, at);
    const sign = offset < 0 ? '-' : '+';
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${wall.date}T${wall.time}:00${sign}${pad(Math.floor(Math.abs(offset) / 60))}:${pad(Math.abs(offset) % 60)}`;
}

/** A moment the server sent, as the field shows it in the store's zone; none for no moment. */
export function momentFrom(iso: string | null, zone: string): MomentInput {
    const at = iso === null ? Number.NaN : new Date(iso).getTime();

    if (Number.isNaN(at)) {
        return noMoment();
    }

    const wall = wallClock(zone, at);

    return { none: false, ...wall, typedAt: 0, saved: `${wall.date} ${wall.time}` };
}

/** Whether the moment is still the one the form opened on, as it was saved. */
export function isSaved(input: MomentInput): boolean {
    return !input.none && input.saved !== undefined && input.saved === `${input.date} ${input.time.trim()}`;
}

/**
 * What is wrong with a typed moment - the key of its sentence (`MOMENT_WORDS`) - or null. Nothing is
 * said about Now, No End or an empty field (the box's `required` says that).
 *
 * @param after  for an end, its start: Now, or the moment typed
 * @param now  the present, for a start the person typed and for an end after Now
 */
export function momentProblem(input: MomentInput, kind: MomentKind, zone: string, after: MomentInput | null = null, now: number = Date.now()): string | null {
    if (input.none || (input.date === '' && input.time.trim() === '')) {
        return null;
    }

    if (!isDay(input.date)) {
        return MOMENT_WORDS.date;
    }

    const at = instantOf(input.date, input.time.trim(), zone);

    if (at === null) {
        return MOMENT_WORDS.time;
    }

    if (kind === 'start') {
        // A saved start left as it was - a running offer's - is not the person's to move into the future.
        if (isSaved(input)) {
            return null;
        }

        // Judged against the minute it was typed in: one that passes afterwards is the server's "now".
        const typed = Math.floor((input.typedAt > 0 ? Math.min(input.typedAt, now) : now) / MINUTE) * MINUTE;

        return at < typed ? MOMENT_WORDS.notPast : null;
    }

    const start = after === null || after.none ? Math.floor(now / MINUTE) * MINUTE : instantOf(after.date, after.time.trim(), zone);

    return start !== null && at <= start ? MOMENT_WORDS.afterStart : null;
}

/** Whether the fault, if any, is the date: a moment typed (not Now, not No End) with no day chosen. */
export function dateWrong(input: MomentInput): boolean {
    return !input.none && !isDay(input.date);
}

/** Whether a typed start, accepted when typed, has passed since: the server will take it as now. */
export function hasPassed(input: MomentInput, zone: string, now: number = Date.now()): boolean {
    const at = input.none || isSaved(input) ? null : instantOf(input.date, input.time.trim(), zone);

    return at !== null && at <= now;
}

/**
 * The moment as one box of its form's checks (lib/use-checks.ts): required unless Now or No End, and
 * its problem said in the words above, naming the field.
 */
export function momentBox(input: MomentInput, field: { id: string; label: string; kind: MomentKind; zone: string; after?: MomentInput | null }): Box {
    return {
        id: `${field.id}-time`,
        // Save finding it wrong puts the focus on the control at fault: the date button while there is no day.
        focus: dateWrong(input) ? `${field.id}-date` : `${field.id}-time`,
        label: field.label,
        value: input.none ? '' : `${input.date} ${input.time}`.trim(),
        off: input.none,
        rules: {
            required: true,
            own: () => momentProblem(input, field.kind, field.zone, field.after ?? null),
        },
    };
}

/** The zone's name for a person: "Saudi Arabia Time", "توقيت السعودية" - Intl's, in the page's language. */
export function zoneName(zone: string, locale: string): string {
    try {
        const part = new Intl.DateTimeFormat(intlLocale(locale), { timeZone: zone, timeZoneName: 'shortGeneric' }).formatToParts(new Date()).find((each) => each.type === 'timeZoneName');

        return part?.value ?? zone;
    } catch {
        return zone;
    }
}

function isDay(day: string): boolean {
    const parts = DAY.exec(day);

    if (parts === null) {
        return false;
    }

    const date = new Date(Date.UTC(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3])));

    // 2026-02-31 is not a day: the date rolls over into March.
    return date.getUTCMonth() === Number(parts[2]) - 1 && date.getUTCDate() === Number(parts[3]);
}

function dayParts(day: string): [number, number, number] {
    const [year, month, date] = day.split('-').map(Number);

    return [year ?? 1970, (month ?? 1) - 1, date ?? 1];
}

function timeParts(time: string): [number, number] {
    const [hour, minute] = time.split(':').map(Number);

    return [hour ?? 0, minute ?? 0];
}
