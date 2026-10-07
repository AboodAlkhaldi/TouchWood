import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchField } from '@/components/SearchField';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useTranslator } from '@/lib/t';
import type { ProductPage, RelatedData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { nameIn, useLocale } from '../parts';
import { SortableList } from '../SortableList';

/*
| A product's related products (catalog.md §1.10, §4.4 S9): two ordered lists - **You May Also Like**
| (picked; left empty, the shop fills it from the same category, then the brand) and **Goes With**
| (picked only) - each with Remove and drag to order, and **Add Product…** searching the ready products
| by name or code, as the products list searches. At most 20 each; each change saved at once.
*/

const KINDS = ['RELATED', 'GOES_WITH'] as const;

type Kind = (typeof KINDS)[number];

export function RelatedTab({ page }: { page: ProductPage }) {
    const { product, mayUpdate } = page;
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props;
    const [adding, setAdding] = useState<Kind | null>(null);
    const [busy, setBusy] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const reason = mayUpdate ? undefined : t('catalog::admin_products.read_only');
    const of = (kind: Kind) => (page.related ?? []).filter((row) => row.kind === kind);

    function save(kind: Kind, ids: string[], then?: () => void) {
        router.post(`/admin/products/${product.id}/related/${kind.toLowerCase()}`, { product_ids: ids }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: then });
    }

    return (
        <div className="grid gap-6">
            {KINDS.map((kind) => (
                <RelatedList
                    key={kind}
                    kind={kind}
                    rows={of(kind)}
                    reason={reason}
                    busy={busy}
                    onSave={(ids) => save(kind, ids)}
                    onAdd={(trigger) => {
                        opener.current = trigger;
                        setAdding(kind);
                    }}
                />
            ))}
            {errors.relations ? <FieldError>{errors.relations}</FieldError> : null}

            {adding !== null ? (
                <FindDialog
                    page={page}
                    kind={adding}
                    taken={of(adding).map((row) => row.productId)}
                    busy={busy}
                    onChoose={(id) => save(adding, [...of(adding).map((row) => row.productId), id], () => setAdding(null))}
                    onOpenChange={(open) => (open ? undefined : setAdding(null))}
                    returnFocusTo={opener}
                />
            ) : null}
        </div>
    );
}

function RelatedList({ kind, rows, reason, busy, onSave, onAdd }: { kind: Kind; rows: RelatedData[]; reason: string | undefined; busy: boolean; onSave: (ids: string[]) => void; onAdd: (trigger: HTMLElement) => void }) {
    const t = useTranslator();
    const locale = useLocale();
    const ids = rows.map((row) => row.productId);
    const label = (row: RelatedData) => `${nameIn(locale, row.nameAr, row.nameEn)}${row.codes.length > 0 ? ` (${row.codes.join(', ')})` : ''}`;

    return (
        <Card className="material-base border-0" data-test={`related-${kind}`}>
            <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <CardTitle className="text-heading-16 text-ink">{t(`catalog::admin_products.related.${kind}`)}</CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t(`catalog::admin_products.related.${kind}_body`)}</CardDescription>
                </div>
                <ActionButton type="button" variant="outline" disabledReason={reason} onClick={(event) => onAdd(event.currentTarget)} data-test={`add-related-${kind}`}>
                    {t('catalog::admin_products.related.add')}
                </ActionButton>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="text-copy-14 text-ink-muted">{t('catalog::admin_products.related.empty')}</p>
                ) : reason === undefined ? (
                    <SortableList
                        testPrefix={`related-${kind}`}
                        items={rows.map((row) => ({
                            id: row.productId,
                            label: label(row),
                            extra: (
                                <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={() => onSave(ids.filter((id) => id !== row.productId))} data-test={`remove-related-${row.productId}`}>
                                    {t('catalog::admin_products.related.remove')}
                                </Button>
                            ),
                        }))}
                        onChange={onSave}
                    />
                ) : (
                    <ul className="grid gap-2">
                        {rows.map((row) => (
                            <li key={row.productId} className="text-copy-14 text-ink">
                                {label(row)}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

/** Finding a ready product to add: the page's own address, its `find` asked again (Inertia's partial reload). */
function FindDialog({
    page,
    kind,
    taken,
    busy,
    onChoose,
    onOpenChange,
    returnFocusTo,
}: {
    page: ProductPage;
    kind: Kind;
    taken: string[];
    busy: boolean;
    onChoose: (id: string) => void;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const found = (page.found ?? []).filter((row) => !taken.includes(row.id));

    // Asked a moment after the last key, not on each one.
    useEffect(() => {
        if (query.trim() === '') {
            return;
        }

        const wait = window.setTimeout(() => {
            router.get(
                `/admin/products/${page.product.id}`,
                { tab: 'related', find: query.trim() },
                { only: ['found'], preserveState: true, preserveScroll: true, preserveUrl: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) },
            );
        }, 300);

        return () => window.clearTimeout(wait);
    }, [query]);

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_products.related.add_title', { list: t(`catalog::admin_products.related.${kind}`) })}
            description={t(`catalog::admin_products.related.${kind}_body`)}
            busy={busy}
            confirm={null}
        >
            <div className="grid gap-3">
                <SearchField id="find-related" label={t('catalog::admin_products.related.find')} placeholder={t('catalog::admin_products.related.find_placeholder')} value={query} onValueChange={setQuery} data-test="find-related" />
                {loading ? (
                    <p className="flex items-center gap-2 text-copy-14 text-ink-muted">
                        <Spinner aria-hidden="true" role={undefined} aria-label={undefined} />
                        {t('ui.loading')}
                    </p>
                ) : query.trim() !== '' && page.found !== null && found.length === 0 ? (
                    <p className="text-copy-14 text-ink-muted">{t('catalog::admin_products.related.find_none')}</p>
                ) : (
                    <ul className="grid gap-2" data-test="found">
                        {found.map((row) => (
                            <li key={row.id} className="material-small flex items-center gap-3 px-3 py-2">
                                <span className="grid">
                                    <span className="text-label-14 text-ink">{nameIn(locale, row.nameAr, row.nameEn)}</span>
                                    <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                        {row.codes.join(' · ')}
                                    </span>
                                </span>
                                <Button type="button" size="sm" className="ms-auto" disabled={busy} onClick={() => onChoose(row.id)} data-test={`choose-${row.id}`}>
                                    {t('catalog::admin_products.related.choose')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PanelDialog>
    );
}
