import { usePage } from '@inertiajs/react';
import { Money } from '@/components/Money';
import { Badge } from '@/components/ui/badge';
import { tone, type Tone } from '@/lib/tones';
import { figure, intlLocale } from '@/lib/digits';
import type { useTranslator } from '@/lib/t';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { SharedProps } from '@/types/page';

/*
| Sales's parts shared by the shop's pages (sales.md §4.1 S2–S10): the words' file, a count said with its
| plural, and a price with the old one struck through on a sale.
*/

export const C = 'sales::shop_cart';

export function useLocale(): string {
    return usePage<SharedProps>().props.locale;
}

/** The plural's own form for this count, in the page's language, its digits Latin. */
export function plural(t: ReturnType<typeof useTranslator>, locale: string, key: string, count: number, values: Record<string, string | number> = {}): string {
    return t(`${key}.${new Intl.PluralRules(intlLocale(locale)).select(count)}`, { count: figure(locale, count), ...values });
}

/** Today's price; on a sale the old one struck through after it (R2-2). */
export function Price({ now, before, className }: { now: MoneyData; before?: MoneyData | null; className?: string }) {
    return (
        <span className={className}>
            <Money value={now} />
            {before ? (
                <>
                    {' '}
                    <s className="text-copy-13 text-ink-muted">
                        <Money value={before} />
                    </s>
                </>
            ) : null}
        </span>
    );
}

export const O = 'sales::shop_orders';

/**
 * The status a customer sees (§1.4; R2-19): New blue, Processing amber, Delivered green, Cancelled and Refunded
 * gray. Shipped is purple in the spec; the tones have no purple yet, so it waits for the one style every badge
 * takes before launch (§9.2 #10) and shows blue-subtle meanwhile.
 */
const STATUS_TONE: Record<string, Tone> = { NEW: 'blue', PROCESSING: 'amber', SHIPPED: 'blue-subtle', DELIVERED: 'green', CANCELLED: 'gray', REFUNDED: 'gray' };

export function OrderStatus({ status, label }: { status: string; label: string }) {
    return (
        <Badge className={tone(STATUS_TONE[status] ?? 'gray')} data-test={`status-${status}`}>
            {label}
        </Badge>
    );
}
