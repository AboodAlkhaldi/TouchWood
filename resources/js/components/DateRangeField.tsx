import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CalendarIcon } from 'lucide-react';
import type { DateRange } from 'react-day-picker';
import { ar as dayPickerArabic } from 'react-day-picker/locale/ar';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Field, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useStoreZone } from '@/components/Time';
import type { SharedProps } from '@/types/page';

/*
| A range of days to filter by (frontend.md §1.11): shadcn's `date-picker-with-range` - a Popover
| holding a range Calendar - with Geist's Calendar rules on top: presets for the common ranges
| ("Last 7 Days", "Month to Date"), nothing after today, and the button showing the range chosen.
| "Today" is the store's day, as every moment in the panel is the store's (§1.10, store time).
|
| The days are plain dates, YYYY-MM-DD, as the filter has always sent them. Figures stay Latin on an
| Arabic page, as typed figures do everywhere in the panel (§1.8).
*/

export type DateRangeWords = {
    /** The button when no range is chosen: "Any Date". */
    any: string;
    presets: { today: string; week: string; month: string; monthToDate: string };
};

type Props = {
    id: string;
    label: string;
    from: string;
    until: string;
    onChange: (from: string, until: string) => void;
    words: DateRangeWords;
};

/** Today in the store's zone, as YYYY-MM-DD. */
function today(zone: string): string {
    // en-CA writes a date as YYYY-MM-DD.
    return new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

function asDate(day: string): Date | undefined {
    if (day === '') {
        return undefined;
    }

    const [year = 0, month = 1, date = 1] = day.split('-').map(Number);

    return new Date(year, month - 1, date);
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

export function DateRangeField({ id, label, from, until, onChange, words }: Props) {
    const { locale } = usePage<SharedProps>().props;
    const zone = useStoreZone();
    const [open, setOpen] = useState(false);
    const now = today(zone);
    const selected: DateRange | undefined = from === '' && until === '' ? undefined : { from: asDate(from), to: asDate(until) };
    const shown = new Intl.DateTimeFormat(locale === 'ar' ? 'ar-u-nu-latn' : 'en', { day: 'numeric', month: 'short', year: 'numeric' });

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
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button id={id} type="button" variant="outline" className="w-full justify-start font-normal" data-test={id}>
                        <CalendarIcon aria-hidden="true" className="opacity-60" />
                        <span className="tw-figure truncate">{text}</span>
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
                    <Calendar
                        mode="range"
                        selected={selected}
                        defaultMonth={asDate(from) ?? asDate(now)}
                        onSelect={(range) => onChange(asDay(range?.from), asDay(range?.to))}
                        disabled={{ after: asDate(now) as Date }}
                        numberOfMonths={2}
                        locale={locale === 'ar' ? dayPickerArabic : undefined}
                        dir={locale === 'ar' ? 'rtl' : 'ltr'}
                        numerals="latn"
                    />
                </PopoverContent>
            </Popover>
        </Field>
    );
}
