import { lazy, Suspense, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CalendarIcon } from 'lucide-react';
import type { DateRange } from 'react-day-picker';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import type { SharedProps } from '@/types/page';

// Loaded when the picker first opens, never with the page (DateRangeCalendar, the page budget).
const DateRangeCalendar = lazy(() => import('@/components/DateRangeCalendar'));

/*
| A range of days to filter by (frontend.md §1.11): shadcn's `date-picker-with-range` - a Popover
| holding a range Calendar - with Geist's Calendar rules on top: presets for the common ranges
| ("Last 7 Days", "Month to Date"), nothing after today, and the button showing the range chosen,
| which a screen reader hears with the field's name.
|
| The days are plain dates, YYYY-MM-DD, as the filter has always sent them, and they are **UTC
| days**, as the server compares them (the audit log's reader): "today" is today in UTC, so the
| presets ask for what they say. The field says so under it. An Arabic page shows Arabic-Indic
| digits, on the button and in the calendar, as every date in the panel does (§1.8). A date the
| address holds that is not a plain day is treated as none, never as a broken page.
*/

export type DateRangeWords = {
    /** The button when no range is chosen: "Any Date". */
    any: string;
    presets: { today: string; week: string; month: string; monthToDate: string };
    /** Under the field: which clock the days are counted in. */
    helper: string;
};

type Props = {
    id: string;
    label: string;
    from: string;
    until: string;
    onChange: (from: string, until: string) => void;
    words: DateRangeWords;
};

const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Today in UTC, as YYYY-MM-DD. */
function today(): string {
    return new Date().toISOString().slice(0, 10);
}

/** A plain day as a calendar date, or undefined when the text is not one. */
function asDate(day: string): Date | undefined {
    const parts = DAY.exec(day);

    if (parts === null) {
        return undefined;
    }

    const date = new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]));

    // 2026-02-31 is not a day: the Date rolls over, and its month no longer matches.
    return Number.isNaN(date.getTime()) || date.getMonth() !== Number(parts[2]) - 1 ? undefined : date;
}

function asDay(date: Date | undefined): string {
    if (date === undefined) {
        return '';
    }

    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function shifted(day: string, days: number): string {
    const date = asDate(day) as Date;
    date.setDate(date.getDate() + days);

    return asDay(date);
}

function monthBefore(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth() - 1, 1);
}

/** The first of the two months shown: the range's start, but never so late the second is after today's. */
function firstShown(start: Date | undefined, latest: Date): Date {
    return start === undefined || start > latest ? latest : start;
}

export function DateRangeField({ id, label, from: askedFrom, until: askedUntil, onChange, words }: Props) {
    const { locale } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const now = today();
    // Only plain days count; anything else in the address is no date at all.
    const from = asDate(askedFrom) === undefined ? '' : askedFrom;
    const until = asDate(askedUntil) === undefined ? '' : askedUntil;
    const selected: DateRange | undefined = from === '' && until === '' ? undefined : { from: asDate(from), to: asDate(until) };
    const shown = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', { day: 'numeric', month: 'short', year: 'numeric' });

    const text =
        from === '' && until === ''
            ? words.any
            : [from === '' ? '…' : shown.format(asDate(from)), until === '' ? '…' : shown.format(asDate(until))].join(' – ');

    const preset = (start: string) => {
        onChange(start, now);
        setOpen(false);
    };

    return (
        <Field>
            <FieldLabel id={`${id}-label`} htmlFor={id}>
                {label}
            </FieldLabel>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        // The field's name and then the range chosen: the label alone would hide it.
                        aria-labelledby={`${id}-label ${id}-value`}
                        aria-describedby={`${id}-helper`}
                        className="w-full justify-start font-normal"
                        data-test={id}
                    >
                        <CalendarIcon aria-hidden="true" className="opacity-60" />
                        {/* Figures only once there are dates: "Any Date" is words. */}
                        <span id={`${id}-value`} className={from === '' && until === '' ? 'truncate' : 'tw-figure truncate'}>
                            {text}
                        </span>
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="flex w-auto flex-col gap-2 p-2 sm:flex-row" align="start">
                    <div className="grid content-start gap-1 sm:w-36" role="group" aria-label={label}>
                        <Button type="button" variant="ghost" size="sm" className="justify-start" onClick={() => preset(now)} data-test={`${id}-today`}>
                            {words.presets.today}
                        </Button>
                        <Button type="button" variant="ghost" size="sm" className="justify-start" onClick={() => preset(shifted(now, -6))} data-test={`${id}-week`}>
                            {words.presets.week}
                        </Button>
                        <Button type="button" variant="ghost" size="sm" className="justify-start" onClick={() => preset(shifted(now, -29))} data-test={`${id}-month`}>
                            {words.presets.month}
                        </Button>
                        <Button type="button" variant="ghost" size="sm" className="justify-start" onClick={() => preset(`${now.slice(0, 8)}01`)} data-test={`${id}-month-to-date`}>
                            {words.presets.monthToDate}
                        </Button>
                    </div>
                    <Suspense
                        fallback={
                            <div className="grid min-h-72 place-items-center sm:min-w-[34rem]">
                                <Spinner className="text-ink-muted" />
                            </div>
                        }
                    >
                        <DateRangeCalendar
                            selected={selected}
                            // Two months, the second this one: nothing after today can be chosen or shown.
                            defaultMonth={firstShown(asDate(from), monthBefore(asDate(now) as Date))}
                            today={asDate(now) as Date}
                            onSelect={(range) => onChange(asDay(range?.from), asDay(range?.to))}
                            arabic={locale === 'ar'}
                        />
                    </Suspense>
                </PopoverContent>
            </Popover>
            <FieldDescription id={`${id}-helper`} className="text-copy-13 text-ink-muted">
                {words.helper}
            </FieldDescription>
        </Field>
    );
}
