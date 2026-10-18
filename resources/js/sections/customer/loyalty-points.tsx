import { Link } from '@inertiajs/react';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Button } from '@/components/ui/button';
import { Item, ItemActions, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { Amount, Points, useDay, useLocale } from '@/pages/Loyalty/Admin/Points/parts';
import type { PointsSectionData } from '@/types/generated/Modules/Loyalty/Presentation/Http/Resource';

/*
| L5 - a customer's points on the staff customer page (loyalty.md §4.1; access.md amendment 72(a)):
| one block per store the reader may read points in where the customer has some, as the page groups
| their addresses (answer 15) - the store, an off one marked; the points, what they are worth, what
| expires next; and View Points where the reader may open that store's points page.
|
| Give Points… ends the section for a reader who may give a first point in a store where the customer
| has none (§9.5 P5). Loyalty sent nothing for a reader who reads points nowhere: the section is not
| drawn at all.
*/

export function Section({ data }: { data: Record<string, unknown> }) {
    const t = useTranslator();
    const locale = useLocale();
    const day = useDay();
    // Loyalty's own shape (PointsCustomerSection): Access hands it over as it came.
    const section = data as unknown as PointsSectionData;
    const link = (code: string) => `/admin/points/customers/${section.customerId}?store=${encodeURIComponent(code)}`;

    return (
        <section className="grid gap-3" data-test="points-section">
            <h2 className="text-heading-16 text-ink">{t('loyalty::admin_points.section.title')}</h2>

            {section.stores.length === 0 ? (
                <p className="text-copy-14 text-ink-muted" data-test="points-none">
                    {t('loyalty::admin_points.section.none_in_stores')}
                </p>
            ) : (
                <div role="list" className="grid gap-2 sm:grid-cols-2">
                    {section.stores.map((store) => (
                        <div key={store.code} role="listitem" data-test={`points-${store.code}`}>
                            <Item variant="outline" className="material-base h-full items-start border-0">
                                <ItemContent className="gap-0.5">
                                    <ItemTitle className="inline-flex items-center gap-1.5 text-label-14 text-ink">
                                        {store.name}
                                        {store.isActive ? null : <StoreOffBadge />}
                                    </ItemTitle>
                                    <ItemDescription className="line-clamp-none text-copy-14 text-ink">
                                        <Points value={store.points} /> · <Amount value={store.worth} />
                                    </ItemDescription>
                                    <ItemDescription className="line-clamp-none text-copy-13 text-ink-muted">
                                        {store.expiring === null
                                            ? t('loyalty::admin_points.card.none_expiring')
                                            : t('loyalty::admin_points.card.expiring_line', { points: figure(locale, store.expiring.points), day: day(store.expiring.day) })}
                                    </ItemDescription>
                                </ItemContent>
                                {store.mayOpen ? (
                                    <ItemActions>
                                        <Button asChild variant="outline" size="sm">
                                            <Link href={link(store.code)} data-test={`view-points-${store.code}`}>
                                                {t('loyalty::admin_points.section.view')}
                                            </Link>
                                        </Button>
                                    </ItemActions>
                                ) : null}
                            </Item>
                        </div>
                    ))}
                </div>
            )}

            {section.giveStoreCode === null ? null : (
                <div>
                    <Button asChild variant="outline" size="sm">
                        {/* It opens the points page, where Change Points… starts on Add. */}
                        <Link href={link(section.giveStoreCode)} data-test="give-points">
                            {`${t('loyalty::admin_points.section.give')}…`}
                        </Link>
                    </Button>
                </div>
            )}
        </section>
    );
}
