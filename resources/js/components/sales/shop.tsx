import { usePage } from '@inertiajs/react';
import { Money } from '@/components/Money';
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
