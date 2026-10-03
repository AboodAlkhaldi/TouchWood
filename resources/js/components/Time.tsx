import { useId } from 'react';
import { usePage } from '@inertiajs/react';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import type { SharedProps } from '@/types/page';

/*
| A moment, in the store's own time (owner, 2026-10-02: "each store has its own time, so a viewer
| from Egypt sees Egypt's time and from KSA sees KSA's"), worn as Geist's Relative Time Card on
| shadcn's HoverCard (frontend.md §1.10, §1.11).
|
| The server and the database keep UTC; nothing is converted on the way. What changes is only how
| the moment is written: in the zone of the store the person is working in - the panel's current
| store, or the shop's store - with the zone's short name beside it, so two people in different
| stores never confuse "5:00" with "5:00".
|
| Geist's rules: in a list (`relative`, the default) a recent moment reads short and relative -
| "2m ago", "5h ago", "Yesterday" - and anything past seven days as a date; hovering or focusing
| opens the card with the full moment in the store's zone and in UTC (§1.11: "the hover shows the
| store's zone and UTC"). On a detail page (`absolute`) the full moment is the text itself. Arabic
| pages get Arabic-Indic digits, as Intl writes them for 'ar' (§1.8).
|
| A hover card is for the eyes: Radix does not announce it. The same two lines are tied to the
| moment as its description, so a screen reader hears them on focus too.
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

/**
 * Geist's short form: "2m ago", "5h ago", "Yesterday" - Intl's narrow style, which writes exactly
 * that in English, with the first letter raised as Geist writes it. Arabic is Intl's own.
 */
function formatRelative(iso: string, locale: string, now: number): string | null {
    const then = instant(iso).getTime();
    const diff = then - now;

    if (Number.isNaN(then) || Math.abs(diff) >= WEEK_MS) {
        return null;
    }

    const words = new Intl.RelativeTimeFormat(language(locale), { numeric: 'auto', style: 'narrow' });
    const minutes = Math.round(diff / 60000);
    const hours = Math.round(minutes / 60);
    const text =
        Math.abs(minutes) < 60
            ? words.format(minutes, 'minute')
            : Math.abs(hours) < 24
              ? words.format(hours, 'hour')
              : words.format(Math.round(hours / 24), 'day');

    return language(locale) === 'en' ? text.charAt(0).toUpperCase() + text.slice(1) : text;
}

type Props = {
    /** An ISO 8601 moment, as the server sends it (UTC). */
    value: string;
    mode?: 'relative' | 'absolute';
    /**
     * False inside a row that is itself a link: a focusable moment inside a link is a control inside
     * a control. The card still opens on hover, and the link's own name carries the row.
     */
    focusable?: boolean;
    'data-test'?: string;
};

export function Time({ value, mode = 'relative', focusable = true, ...rest }: Props) {
    const { locale } = usePage<SharedProps>().props;
    const zone = useStoreZone();
    const described = useId();
    const full = formatMoment(value, zone, locale);

    if (mode === 'absolute') {
        return (
            <time {...rest} dateTime={value} className="tw-figure">
                {full}
            </time>
        );
    }

    const short = formatRelative(value, locale, Date.now()) ?? formatDate(value, zone, locale);
    const utc = zone === 'UTC' ? null : formatMoment(value, 'UTC', locale);

    return (
        <HoverCard openDelay={150} closeDelay={100}>
            <HoverCardTrigger asChild>
                <time
                    {...rest}
                    dateTime={value}
                    tabIndex={focusable ? 0 : undefined}
                    aria-describedby={described}
                    suppressHydrationWarning
                    className="tw-figure cursor-default underline decoration-dotted decoration-line-strong underline-offset-4"
                >
                    {short}
                </time>
            </HoverCardTrigger>
            <HoverCardContent className="material-menu w-auto border-0 px-3 py-2 text-copy-13" data-test="time-card">
                <p className="tw-figure text-ink">{full}</p>
                {utc === null ? null : <p className="tw-figure text-ink-muted">{utc}</p>}
            </HoverCardContent>
            <span id={described} hidden>
                {utc === null ? full : `${full} · ${utc}`}
            </span>
        </HoverCard>
    );
}
