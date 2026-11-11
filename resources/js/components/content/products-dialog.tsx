import { useEffect, useRef, useState } from 'react';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInLanguage } from '@/lib/in-language';
import { latestReads } from '@/lib/latest-read';
import { readJson } from '@/lib/live-read';
import { useTranslator } from '@/lib/t';
import type { ProductChoiceData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| Add Product's dialog (content.md §1.12, §1.13), loaded when it is asked: the store's ready products, read from the
| server 300 ms after the last key (`GET …/products?store=&q=`), 20 at a time with Show More (`after`, §9.3c S4), each
| added once, up to the kind's limit. Done closes it. **An older answer is dropped** (PR 7b's review): a read is aborted
| when the search changes, and only the latest read's answer is taken, so "dra" answering after "drawer" shows nothing
| of "dra".
*/

type Answer = { products: ProductChoiceData[]; next: string | null };

export function ProductDialog({ search, store, chosen, max, onAdd, onClose }: { search: string; store: string; chosen: ProductChoiceData[]; max: number; onAdd: (product: ProductChoiceData) => void; onClose: () => void }) {
    const t = useTranslator();
    const name = useInLanguage();
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<ProductChoiceData[] | null>(null);
    const [next, setNext] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const [loading, setLoading] = useState(false);
    const latest = useRef(latestReads()).current;
    const full = chosen.length >= max;

    const read = (typed: string, after: string | null, signal?: AbortSignal) => {
        const mine = latest.start();
        setLoading(true);

        return readJson<Answer>(search, { store, q: typed, after }, signal).then((answer) => {
            if (signal?.aborted || !mine()) {
                return;
            }

            setLoading(false);
            setFailed(answer === null);

            if (answer !== null) {
                setFound((was) => (after === null ? answer.products : [...(was ?? []), ...answer.products]));
                setNext(answer.next);
            }
        });
    };

    useEffect(() => {
        const typed = query.trim();

        if (typed === '') {
            // A read still on its way is no longer wanted.
            latest.drop();
            setFound(null);
            setNext(null);
            setLoading(false);

            return;
        }

        const controller = new AbortController();
        const wait = setTimeout(() => void read(typed, null, controller.signal), 300);

        return () => {
            clearTimeout(wait);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `read` is this effect's own
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
                {next !== null ? (
                    <Button type="button" variant="outline" size="sm" disabled={loading} onClick={() => void read(query.trim(), next)} data-test="product-more">
                        {t('content::admin_documents.products.more')}
                    </Button>
                ) : null}
            </div>
        </PanelDialog>
    );
}
