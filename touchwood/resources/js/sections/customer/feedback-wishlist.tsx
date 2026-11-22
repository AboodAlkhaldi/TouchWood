import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Time } from '@/components/Time';
import { ProductCell } from '@/components/feedback/parts';
import { useTranslator } from '@/lib/t';
import type { WishlistSectionData } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| F4 - a customer's wishlist on the staff customer page (feedback.md §4.1; access.md amendment 72(a)):
| one block per store the reader covers, each saved product with its date saved, one no longer sold
| there marked. Feedback sent nothing for a reader without the job: the section is not drawn at all.
*/

export function Section({ data }: { data: Record<string, unknown> }) {
    const t = useTranslator();
    // Feedback's own shape (WishlistCustomerSection): Access hands it over as it came.
    const section = data as unknown as WishlistSectionData;

    return (
        <section className="grid gap-3" data-test="wishlist-section">
            <h2 className="text-heading-16 text-ink">{t('feedback::admin_support.wishlist.title')}</h2>

            {section.stores.length === 0 ? (
                <p className="text-copy-14 text-ink-muted" data-test="wishlist-none">
                    {t('feedback::admin_support.wishlist.none')}
                </p>
            ) : (
                section.stores.map((store) => (
                    <div key={store.name} className="material-base grid gap-2 p-4" data-test="wishlist-store">
                        <h3 className="inline-flex items-center gap-1.5 text-label-14 text-ink">
                            {store.name}
                            {store.isOpen ? null : <StoreOffBadge />}
                        </h3>
                        <ul className="grid gap-2">
                            {store.items.map((item) => (
                                <li key={item.product.id} className="flex flex-wrap items-center justify-between gap-2">
                                    <ProductCell product={item.product} />
                                    <span className="text-copy-13 text-ink-muted">
                                        <Time value={item.savedAt} />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))
            )}
        </section>
    );
}
