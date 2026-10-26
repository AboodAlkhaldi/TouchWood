import { useTranslator } from '@/lib/t';
import type { ProductCardData } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| A product on the shop account's Feedback pages (feedback.md §4.1 F8): its photo, its name - a link in
| the store it belongs to, unless the catalog no longer has it - and the store, when the page spans them.
*/

export function AccountProduct({ product, store }: { product: ProductCardData; store?: string }) {
    const t = useTranslator();

    return (
        <span className="flex min-w-0 items-center gap-3">
            {product.photo === null ? (
                <span className="size-12 shrink-0 rounded-md bg-surface-sunken" aria-hidden="true" />
            ) : (
                <img src={product.photo} alt="" className="size-12 shrink-0 rounded-md object-cover" />
            )}
            <span className="grid min-w-0 gap-0.5">
                {product.href === null ? (
                    <span className="truncate text-label-14 text-ink">{product.name}</span>
                ) : (
                    <a href={product.href} className="truncate text-label-14 text-ink underline-offset-2 hover:underline">
                        {product.name}
                    </a>
                )}
                {store === undefined ? null : <span className="text-copy-13 text-ink-muted">{t('feedback::shop.my.in_store', { store })}</span>}
            </span>
        </span>
    );
}
