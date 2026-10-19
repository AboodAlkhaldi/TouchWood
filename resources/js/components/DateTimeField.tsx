import { lazy, Suspense, useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CalendarIcon } from 'lucide-react';
import { describedBy, Messages } from '@/components/Fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { intlLocale, toLatinDigits } from '@/lib/digits';
import { hasPassed, type MomentInput, type MomentKind, wallClock, zoneName } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import type { BoxCheck } from '@/lib/use-checks';
import type { SharedProps } from '@/types/page';

// Loaded when the picker first opens, never with the page (DateCalendar, the page budget).
const DateCalendar = lazy(() => import('@/components/DateCalendar'));

/*
| A moment typed in the store's clock (pricing.md §4.3.1 "Moments", Q4): neither Geist nor shadcn has a
| date-and-time picker, so it is built from shadcn's parts as the owner chose - a Calendar in a Popover,
| as DateRangeField uses them, and a time box (24-hour HH:MM) - with the store's zone named beside it
| ("Saudi Arabia Time"), never the device's.
|
| A start offers **Now**, an end **No End** - a checkbox, ticked on a new form - which sends nothing.
| Its form declares the moment as one box of its checks (`momentBox`, lib/moments.ts), so a past start
| or an end not after its start is said under it as it is typed, and Save waits for it; the form sends
| `isoOf(value, zone)`, ISO 8601 with the store's offset. A start that passes while the form is open is
| not refused - the server takes it as now - and the field says so.
|
| Days before the first that can be chosen (the store's today; for an end, its start's day) cannot be
| picked; the time box takes Arabic-Indic digits and keeps them as 0-9 (frontend.md §1.8).
*/

type Props = {
    id: string;
    /** The field's name, its legend: "Start", "End". */
    label: string;
    kind: MomentKind;
    value: MomentInput;
    onChange: (next: MomentInput) => void;
    /** The store's zone (IANA), whose clock the moment is typed in. */
    zone: string;
    /** For an end: its start, whose day is the first the end can be on. */
    after?: MomentInput | null;
    /** The moment's check, from its form's useChecks (`momentBox`). */
    check?: BoxCheck;
    /** The server's refusal, for a form without checks. */
    error?: string;
    disabled?: boolean;
};

/** A plain day as day-picker's local date, or undefined when the text is not one. */
function asDate(day: string): Date | undefined {
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);

    return parts === null ? undefined : new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]));
}

function asDay(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The present, read again every half minute: a start typed a minute ago may have passed since. */
function useNow(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 30_000);

        return () => clearInterval(timer);
    }, []);

    return now;
}

export function DateTimeField({ id, label, kind, value, onChange, zone, after = null, check, error, disabled = false }: Props) {
    const { locale } = usePage<SharedProps>().props;
    const t = useTranslator();
    const now = useNow();
    const [open, setOpen] = useState(false);
    const shown = check === undefined ? error : check.message;
    const today = wallClock(zone, now).date;
    // The first day that can be chosen: the store's today, and for an end not before its start's day.
    const earliest = after !== null && !after.none && after.date > today ? after.date : today;
    const day = asDate(value.date);
    const timeId = `${id}-time`;
    const zoneId = `${id}-zone`;
    const passed = kind === 'start' && !value.none && !shown && hasPassed(value, zone, now);
    const change = (next: Partial<MomentInput>) => {
        onChange({ ...value, ...next, typedAt: Date.now() });
        check?.onType();
    };

    return (
        <FieldSet className="gap-3" data-test={id}>
            <FieldLegend variant="label" className="text-label-14 text-ink">
                {label}
            </FieldLegend>
            <Field orientation="horizontal">
                <Checkbox
                    id={`${id}-none`}
                    checked={value.none}
                    disabled={disabled}
                    onCheckedChange={(on) => change({ none: on === true })}
                    data-test={`${id}-none`}
                />
                <FieldLabel htmlFor={`${id}-none`} className="font-normal">
                    {kind === 'start' ? t('ui.moment.now') : t('ui.moment.no_end')}
                </FieldLabel>
            </Field>

            {value.none ? null : (
                <div className="flex flex-wrap items-end gap-3">
                    <Field className="w-auto">
                        <FieldLabel id={`${id}-date-label`} htmlFor={`${id}-date`}>
                            {t('ui.moment.date')}
                        </FieldLabel>
                        <Popover open={open} onOpenChange={setOpen}>
                            <PopoverTrigger asChild>
                                <Button
                                    id={`${id}-date`}
                                    type="button"
                                    variant="outline"
                                    disabled={disabled}
                                    // The field's name and then the day chosen: the label alone would hide it.
                                    aria-labelledby={`${id}-date-label ${id}-date-value`}
                                    className="min-w-40 justify-start font-normal"
                                    data-test={`${id}-date`}
                                >
                                    <CalendarIcon aria-hidden="true" className="opacity-60" />
                                    <span id={`${id}-date-value`} className={day === undefined ? 'truncate' : 'tw-figure truncate'}>
                                        {day === undefined
                                            ? t('ui.moment.pick_date')
                                            : new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', year: 'numeric' }).format(day)}
                                    </span>
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent className="w-auto p-2" align="start">
                                <Suspense
                                    fallback={
                                        <div className="grid min-h-72 min-w-64 place-items-center">
                                            <Spinner className="text-ink-muted" aria-label={t('ui.loading')} />
                                        </div>
                                    }
                                >
                                    <DateCalendar
                                        selected={day}
                                        earliest={asDate(earliest) as Date}
                                        today={asDate(today) as Date}
                                        onSelect={(picked) => {
                                            if (picked !== undefined) {
                                                change({ date: asDay(picked) });
                                                setOpen(false);
                                            }
                                        }}
                                        arabic={locale === 'ar'}
                                    />
                                </Suspense>
                            </PopoverContent>
                        </Popover>
                    </Field>
                    <Field className="w-28">
                        <FieldLabel htmlFor={timeId}>{t('ui.moment.time')}</FieldLabel>
                        <Input
                            id={timeId}
                            dir="ltr"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={5}
                            placeholder="14:30"
                            className="tw-figure"
                            disabled={disabled}
                            value={value.time}
                            onChange={(event) => change({ time: toLatinDigits(event.target.value) })}
                            onBlur={() => check?.onLeave()}
                            aria-required={check?.required ? true : undefined}
                            aria-invalid={shown ? true : undefined}
                            aria-describedby={[zoneId, describedBy(timeId, undefined, shown)].filter(Boolean).join(' ')}
                            data-test={timeId}
                        />
                    </Field>
                    <span id={zoneId} className="pb-2 text-copy-13 text-ink-muted" data-test={zoneId}>
                        {zoneName(zone, locale)}
                    </span>
                </div>
            )}

            <Messages id={timeId} error={shown} check={check} />
            {passed ? (
                <FieldDescription className="text-copy-13 text-ink-muted" data-test={`${id}-passed`}>
                    {t('ui.moment.passed')}
                </FieldDescription>
            ) : null}
        </FieldSet>
    );
}
