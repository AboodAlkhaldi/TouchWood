import { Money } from '@/components/Money';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/cn';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { ShopCardPriceData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';

/*
| The shop's prices (sales.md §4.1 S1; catalog.md amendment 35), as the server wrote them, without VAT: a
| price, and on a sale the old one struck through beside it with its percent off ("−15%", R2-2) - the badge
| in the system's tones until the one style every flag, tag and badge takes before launch (§9.2 #10).
*/

/** The percent a sale takes off, whole; none unless the old price is higher. */
function percentOff(now: MoneyData, before: MoneyData | null): number {
    return before === null || before.minor <= now.minor ? 0 : Math.round(((before.minor - now.minor) * 100) / before.minor);
}

export function ShopPrice({ now, before = null, className }: { now: MoneyData; before?: MoneyData | null; className?: string }) {
    const t = useTranslator();
    const off = percentOff(now, before);

    return (
        <span className={cn('flex flex-wrap items-center gap-x-2 gap-y-1', className)} data-test="price">
            <Money value={now} className="font-semibold text-ink" />
            {before !== null && before.minor > now.minor ? (
                <s className="text-copy-13 text-ink-muted" data-test="price-before">
                    <span className="sr-only">{t('catalog::shop.price.was')} </span>
                    <Money value={before} />
                </s>
            ) : null}
            {off > 0 ? (
                <Badge className={tone('red')} data-test="price-off">
                    <bdi dir="ltr" className="tw-figure">
                        {t('catalog::shop.price.off', { percent: off })}
                    </bdi>
                </Badge>
            ) : null}
        </span>
    );
}

/** A card's price (amendment 35(a)): "From" the lowest where its sizes differ, else today's with any sale; a guest's or a company's "Wholesale from" under it. */
export function CardPrice({ price }: { price: ShopCardPriceData | null }) {
    const t = useTranslator();

    if (price === null || (price.now === null && price.wholesaleFrom === null)) {
        return null;
    }

    return (
        <span className="grid gap-0.5 text-copy-14" data-test="card-price">
            {price.from !== null ? (
                <span className="flex flex-wrap items-center gap-x-1.5" data-test="price-from">
                    <span className="text-ink-muted">{t('catalog::shop.price.from')}</span>
                    <Money value={price.from} className="font-semibold text-ink" />
                </span>
            ) : price.now !== null ? (
                <ShopPrice now={price.now} before={price.before} />
            ) : null}
            {price.wholesaleFrom !== null ? (
                <span className="flex flex-wrap items-center gap-x-1.5 text-copy-13 text-ink-muted" data-test="wholesale-from">
                    <span>{t('catalog::shop.price.wholesale_from')}</span>
                    <Money value={price.wholesaleFrom} />
                </span>
            ) : null}
        </span>
    );
}
