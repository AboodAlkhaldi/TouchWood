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

/**
 * The zone the person is working in: the panel's current store, else the shop's. A page with
 * neither - the panel's sign-in - writes UTC, and says so beside the time.
 */
export function useStoreZone(): string {
    const { store, shop } = usePage<SharedProps>().props;

    return store?.current?.timezone ?? shop?.timezone ?? 'UTC';
}

/**
 * The same writing for a moment inside a sentence ("Trusted until :date"), where a component
 * cannot go: the full moment, or its date alone, in the store's zone and the page's digits.
 */
export function useMoments(): { full: (iso: string) => string; date: (iso: string) => string } {
    const { locale } = usePage<SharedProps>().props;
    const zone = useStoreZone();

    return { full: (iso) => formatMoment(iso, zone, locale), date: (iso) => formatDate(iso, zone, locale) };
}

function language(locale: string): string {
    return locale === 'ar' ? 'ar' : 'en';
}

/**
 * A moment as the server sent it, read as the instant it is. The server and the database keep UTC
 * (owner), and their strings come in several shapes - ATOM ("…T05:00:00+00:00"), PostgreSQL's own
 * ("… 05:00:00.123456+00"), a bare date - so each is brought to one JavaScript can read; one with
 * no offset at all is UTC.
 */
function instant(value: string): Date {
    let text = value.trim();

    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
        return new Date(`${text}T00:00:00Z`);
    }

    text = text.replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1').replace(/([+-]\d{2})$/, '$1:00').replace(/([+-]\d{2})(\d{2})$/, '$1:$2');

    return new Date(/(Z|[+-]\d{2}:\d{2})$/.test(text) ? text : `${text}Z`);
}

export function formatMoment(iso: string, zone: string, locale: string): string {
    const at = instant(iso);

    if (Number.isNaN(at.getTime())) {
        return iso;
    }

    return new Intl.DateTimeFormat(language(locale), {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: zone,
        timeZoneName: 'short',
    }).format(at);
}

export function formatDate(iso: string, zone: string, locale: string): string {
    const at = instant(iso);

    return Number.isNaN(at.getTime())
        ? iso
        : new Intl.DateTimeFormat(language(locale), { year: 'numeric', month: 'short', day: 'numeric', timeZone: zone }).format(at);
}

function formatRelative(iso: string, locale: string, now: number): string | null {
    const then = instant(iso).getTime();
    const diff = then - now;

    if (Number.isNaN(then) || Math.abs(diff) >= WEEK_MS) {
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
