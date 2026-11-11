import { useEffect, useState } from 'react';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import type { ProductChoiceData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| Add Product's dialog (content.md §1.12, §1.13), loaded when it is asked: the store's ready products, read from the
| server 300 ms after the last key (`GET …/products?store=&q=`), each added once, up to the kind's limit. Done closes it.
*/

export function ProductDialog({ search, store, chosen, max, onAdd, onClose }: { search: string; store: string; chosen: ProductChoiceData[]; max: number; onAdd: (product: ProductChoiceData) => void; onClose: () => void }) {
    const t = useTranslator();
    const name = useInLanguage();
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<ProductChoiceData[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [loading, setLoading] = useState(false);
    const full = chosen.length >= max;

    useEffect(() => {
        const typed = query.trim();

        if (typed === '') {
            setFound(null);

            return;
        }

        const wait = setTimeout(() => {
            setLoading(true);
            fetch(`${search}?${new URLSearchParams({ store, q: typed }).toString()}`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((response) => (response.ok ? (response.json() as Promise<{ products: ProductChoiceData[] }>) : Promise.reject(new Error(String(response.status)))))
                .then((answer) => {
                    setFound(answer.products);
                    setFailed(false);
                })
                .catch(() => setFailed(true))
                .finally(() => setLoading(false));
        }, 300);

        return () => clearTimeout(wait);
    }, [query, search, store]);

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={t('content::admin_documents.products.title')}
            description={full ? t('content::admin_documents.products.full', { max }) : t('content::admin_documents.products.empty')}
            busy={false}
            cancelLabel={t('content::admin_documents.products.close')}
            confirm={null}
        >
            <div className="grid gap-3">
                <Input type="search" aria-label={t('content::admin_documents.products.search')} placeholder={t('content::admin_documents.products.search')} value={query} onChange={(event) => setQuery(event.target.value)} data-test="product-search" />
                {loading ? <Spinner /> : null}
                {failed ? <p className="text-copy-14 text-bad">{t('content::admin_documents.products.failed')}</p> : null}
                {found !== null && found.length === 0 ? <p className="text-copy-14 text-ink-muted">{t('content::admin_documents.products.none', { query: query.trim() })}</p> : null}
                <ul className="grid max-h-72 gap-1 overflow-y-auto">
                    {(found ?? []).map((product) => {
                        const added = chosen.some((each) => each.id === product.id);

                        return (
                            <li key={product.id} className="flex min-w-0 items-center justify-between gap-2">
                                <span className="truncate text-copy-14 text-ink">{name(product.nameAr, product.nameEn ?? product.nameAr)}</span>
                                <Button type="button" size="sm" variant="outline" disabled={added || full} onClick={() => onAdd(product)} data-test={`choose-product-${product.id}`}>
                                    {added ? t('content::admin_documents.products.added') : t('content::admin_documents.products.add')}
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </PanelDialog>
    );
}
