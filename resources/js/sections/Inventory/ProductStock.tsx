import { lazy, Suspense, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import type { StockFiguresData } from '@/types/generated/Modules/Inventory/Presentation/Http/Resource';
import type { StoreSectionProps } from '@/pages/Catalog/Admin/Products/store-sections';
import { CountBox, useCounts } from '@/pages/Inventory/Admin/counts';
import type { AdjustTarget } from '@/pages/Inventory/Admin/dialogs';
import { InStock, InventoryWords, StockBadge, threshold, useLocale, useT, type Locale } from '@/pages/Inventory/Admin/parts';

// The dialogs load when one is first opened, not with the tab.
const AdjustDialog = lazy(() => import('@/pages/Inventory/Admin/dialogs').then((module) => ({ default: module.AdjustDialog })));
const ThresholdDialog = lazy(() => import('@/pages/Inventory/Admin/dialogs').then((module) => ({ default: module.ThresholdDialog })));

/*
| **Inventory's part of a store card** on a product's Stores tab (inventory.md §4.3.3, the product page; Catalog's
| amendment 23(e)), for whoever manages stock in that store: the product's **Last Pieces** switch (no provider);
| each size's In Stock - a box to count in, no provider; the provider's number, wired -, Held, Available with
| its badge, Threshold, wired: Counts on Stock; the save bar inside the card; each size's ⋯ of Add or Remove
| Stock…, Set Threshold…, View Stock (Stock's drawer). Each card's boxes are its own - ids with the store's code,
| a refusal marking only its own rows, the store named in each box's label. Its words travel with its data.
*/

type Card = {
    storeId: string;
    wired: boolean;
    off: boolean;
    lastPieces: boolean | null;
    sizes: { variantId: string; figures: StockFiguresData }[];
    defaultThreshold: number;
    words: Record<string, string>;
};

type HostPage = {
    variants?: { id: string; code: string; values: { nameAr: string; nameEn: string }[] }[] | null;
    product?: { id: string };
};

type Dialog = { kind: 'adjust'; size: AdjustTarget } | { kind: 'threshold'; size: string; name: string; current: number | null } | null;

export default function ProductStock({ data, store }: StoreSectionProps) {
    const card = data as Card;

    return (
        <InventoryWords.Provider value={card.words}>
            <Section card={card} store={{ code: store.code, name: store.name }} />
        </InventoryWords.Provider>
    );
}

function sizeLabel(locale: Locale, oneSize: string, values: { nameAr: string; nameEn: string }[]): string {
    return values.length === 0 ? oneSize : values.map((value) => (locale === 'ar' ? value.nameAr : value.nameEn)).join(' · ');
}

function Section({ card, store }: { card: Card; store: { code: string; name: string } }) {
    const t = useT();
    const locale = useLocale();
    const host = usePage().props as unknown as HostPage;
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const variants = new Map((host.variants ?? []).map((variant) => [variant.id, variant]));
    const nameOf = (id: string) => sizeLabel(locale, t('inventory::admin_stock.one_size'), variants.get(id)?.values ?? []);
    const countable = !card.wired;
    const counts = useCounts(
        countable ? card.sizes.map((size) => ({ id: size.variantId, inStock: size.figures.inStock, held: size.figures.held, holdingOrders: size.figures.holdingOrders })) : [],
        // Each store card's boxes their own: ids, refusals and save bar.
        { store: store.code, prefix: `count-${store.code}` },
    );

    return (
        <div className="grid gap-4" data-test={`inventory-section-${store.code}`}>
            <h3 className="text-heading-14 text-ink">{t('inventory::admin_stock.section.title')}</h3>
            {card.lastPieces !== null ? (
                <Field orientation="horizontal">
                    <Switch
                        id={`last-pieces-${store.code}`}
                        checked={card.lastPieces}
                        onCheckedChange={(on) => router.post('/admin/stock/last-pieces', { store: store.code, product: host.product?.id ?? '', on }, { preserveScroll: true })}
                        data-test={`last-pieces-${store.code}`}
                    />
                    <FieldContent>
                        <FieldLabel htmlFor={`last-pieces-${store.code}`}>{t('inventory::admin_stock.section.last_pieces')}</FieldLabel>
                        <FieldDescription>{t('inventory::admin_stock.section.last_pieces_helper')}</FieldDescription>
                    </FieldContent>
                </Field>
            ) : null}
            <div className="overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{t('inventory::admin_stock.section.title')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            <TableHead>{t('inventory::admin_stock.column.size')}</TableHead>
                            <TableHead>{t('inventory::admin_stock.column.in_stock')}</TableHead>
                            <TableHead>{t('inventory::admin_stock.column.held')}</TableHead>
                            <TableHead>{t('inventory::admin_stock.column.available')}</TableHead>
                            <TableHead>{t('inventory::admin_stock.column.threshold')}</TableHead>
                            {card.wired ? <TableHead>{t('inventory::admin_stock.column.counts_on_stock')}</TableHead> : null}
                            <TableHead className="w-12">
                                <span className="sr-only">{t('inventory::admin_stock.actions')}</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {card.sizes.map((size) => {
                            const id = size.variantId;
                            const name = nameOf(id);
                            const countRow = { id, inStock: size.figures.inStock, held: size.figures.held, holdingOrders: size.figures.holdingOrders };

                            return (
                                <TableRow key={id} data-test={`section-stock-${id}`}>
                                    <TableCell>
                                        <span className="grid">
                                            <span>{name}</span>
                                            <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                                {variants.get(id)?.code ?? ''}
                                            </span>
                                        </span>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        {countable ? (
                                            <CountBox
                                                id={counts.idOf(id)}
                                                label={t('inventory::admin_stock.box.label_store', { size: name, store: store.name })}
                                                value={counts.valueOf(countRow)}
                                                onChange={(value) => counts.type(id, value)}
                                                onEscape={() => counts.reset(id)}
                                                onEnter={counts.save}
                                                changed={counts.changed(id)}
                                                error={counts.error(id)}
                                                warning={counts.warning(countRow)}
                                            />
                                        ) : (
                                            <InStock figures={size.figures} wired />
                                        )}
                                    </TableCell>
                                    <TableCell className="tw-figure align-top">{figure(locale, size.figures.held)}</TableCell>
                                    <TableCell className="align-top">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="tw-figure">{figure(locale, size.figures.available)}</span>
                                            <StockBadge figures={size.figures} />
                                        </span>
                                    </TableCell>
                                    <TableCell className="tw-figure align-top">{threshold(t, locale, size.figures)}</TableCell>
                                    {card.wired ? (
                                        <TableCell className="align-top">
                                            <Switch
                                                checked={size.figures.stockDependent}
                                                onCheckedChange={(on) => router.post('/admin/stock/counts-on-stock', { store: store.code, sizes: [id], on }, { preserveScroll: true })}
                                                aria-label={t('inventory::admin_stock.counts_on_label', { size: `${name} (${store.name})` })}
                                                data-test={`counts-on-${store.code}-${id}`}
                                            />
                                        </TableCell>
                                    ) : null}
                                    <TableCell className="text-end align-top">
                                        <SizeMenu
                                            name={`${name} (${store.name})`}
                                            store={store.code}
                                            size={id}
                                            wired={card.wired}
                                            onAdjust={(trigger) => {
                                                opener.current = trigger;
                                                setDialog({ kind: 'adjust', size: { variantId: id, name, figures: size.figures } });
                                            }}
                                            onThreshold={(trigger) => {
                                                opener.current = trigger;
                                                setDialog({ kind: 'threshold', size: id, name, current: size.figures.ownThreshold });
                                            }}
                                        />
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
            {countable ? counts.bar() : null}

            <Suspense fallback={null}>
                {dialog?.kind === 'adjust' ? <AdjustDialog store={store.code} size={dialog.size} onOpenChange={(open) => (open ? undefined : setDialog(null))} returnFocusTo={opener} /> : null}
                {dialog?.kind === 'threshold' ? (
                    <ThresholdDialog
                        store={store.code}
                        sizes={[dialog.size]}
                        name={dialog.name}
                        current={dialog.current}
                        defaultThreshold={card.defaultThreshold}
                        onOpenChange={(open) => (open ? undefined : setDialog(null))}
                        returnFocusTo={opener}
                    />
                ) : null}
            </Suspense>
        </div>
    );
}

function SizeMenu({ name, store, size, wired, onAdjust, onThreshold }: { name: string; store: string; size: string; wired: boolean; onAdjust: (trigger: HTMLElement | null) => void; onThreshold: (trigger: HTMLElement | null) => void }) {
    const t = useT();
    const more = useRef<HTMLButtonElement>(null);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} data-test={`section-stock-actions-${store}-${size}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                {wired ? null : <Guarded label={t('inventory::admin_stock.menu.adjust')} onSelect={() => onAdjust(more.current)} test="adjust-stock" />}
                <Guarded label={t('inventory::admin_stock.menu.threshold')} onSelect={() => onThreshold(more.current)} test="set-threshold" />
                <Guarded label={t('inventory::admin_stock.menu.view')} onSelect={() => router.get('/admin/stock', { store, size })} test="view-stock" />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
