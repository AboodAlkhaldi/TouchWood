import { usePage } from '@inertiajs/react';
import { Tooltip } from './Tooltip';
import type { SharedProps } from '@/types/page';

/*
| A moment, in the store's own time (owner, 2026-10-02: "each store has its own time, so a viewer
| from Egypt sees Egypt's time and from KSA sees KSA's"), worn as Geist's Relative Time Card
| (frontend.md 1.10).
|
| The server and the database keep UTC; nothing is converted on the way. What changes is only how
| the moment is written: in the zone of the store the person is working in - the panel's current
| store, or the shop's store - with the zone's short name beside it, so two people in different
| stores never confuse "5:00" with "5:00".
|
| Geist's rules: in a list (`relative`, the default) a recent moment reads short and relative -
| "2h ago", "Yesterday" - and anything past seven days as a date; hovering or focusing shows the
| full moment. On a detail page (`absolute`) the full moment is the text itself. Arabic pages get
| Arabic-Indic digits, as Intl writes them for 'ar' (1.8).
|
| "now" differs between the server's render and the browser's, so the relative words may change by
| a minute at hydration; that one text is allowed to.
*/

const WEEK_MS = 7 * 24 * 60 * 60 * 1000;

/** The zone the person is working in: the panel's current store, else the shop's. */
export function useStoreZone(): string {
    const props = usePage<SharedProps>().props as SharedProps & {
        store?: { current?: { timezone?: string } | null } | null;
        shop?: { timezone?: string } | null;
    };

    return props.store?.current?.timezone ?? props.shop?.timezone ?? 'UTC';
}

function language(locale: string): string {
    return locale === 'ar' ? 'ar' : 'en';
}

export function formatMoment(iso: string, zone: string, locale: string): string {
    return new Intl.DateTimeFormat(language(locale), {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: zone,
        timeZoneName: 'short',
    }).format(new Date(iso));
}

function formatDate(iso: string, zone: string, locale: string): string {
    return new Intl.DateTimeFormat(language(locale), { year: 'numeric', month: 'short', day: 'numeric', timeZone: zone }).format(new Date(iso));
}

function formatRelative(iso: string, locale: string, now: number): string | null {
    const then = new Date(iso).getTime();
    const diff = then - now;

    if (Math.abs(diff) >= WEEK_MS) {
        return null;
    }

    const words = new Intl.RelativeTimeFormat(language(locale), { numeric: 'auto', style: 'short' });
    const minutes = Math.round(diff / 60000);

    if (Math.abs(minutes) < 60) {
        return words.format(minutes, 'minute');
    }

    const hours = Math.round(minutes / 60);

    return Math.abs(hours) < 24 ? words.format(hours, 'hour') : words.format(Math.round(hours / 24), 'day');
}

type Props = {
    /** An ISO 8601 moment, as the server sends it (UTC). */
    value: string;
    mode?: 'relative' | 'absolute';
    'data-test'?: string;
};

export function Time({ value, mode = 'relative', ...rest }: Props) {
    const { locale } = usePage<SharedProps>().props;
    const zone = useStoreZone();
    const full = formatMoment(value, zone, locale);

    if (mode === 'absolute') {
        return (
            <time {...rest} dateTime={value} className="tw-figure">
                {full}
            </time>
        );
    }

    const short = formatRelative(value, locale, Date.now()) ?? formatDate(value, zone, locale);

    return (
        <Tooltip text={full}>
            <time {...rest} dateTime={value} tabIndex={0} suppressHydrationWarning className="tw-figure cursor-default underline decoration-dotted decoration-line-strong underline-offset-4">
                {short}
            </time>
        </Tooltip>
    );
}
