import { Fragment, lazy, Suspense, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Separator } from '@/components/ui/separator';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { AttributeChoiceData, ProductPage, VariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, MoreButtonOff, nameIn, useLocale } from '../parts';
import { DragHandle, MoveItems, SortableGroup, SortableGroups, useSavedOrder, useSortableRow } from '../SortableList';
import { ProductShell } from './shell';
import { AttributesPanel } from './variant-attributes';

const VariantDialog = lazy(() => import('./variant-dialogs').then((module) => ({ default: module.VariantDialog })));
const CodeDialog = lazy(() => import('./variant-dialogs').then((module) => ({ default: module.CodeDialog })));
const VariantPhotosDialog = lazy(() => import('./variant-dialogs').then((module) => ({ default: module.VariantPhotosDialog })));
const DeleteVariantDialog = lazy(() => import('./variant-dialogs').then((module) => ({ default: module.DeleteVariantDialog })));

/*
| A product's variants (catalog.md §4.4 S9): Has Variants and its attributes above (variant-attributes.tsx),
| then the table in the product's order, dragged into another by each row's handle (P29): code · one
| column per attribute · details · weight and size · photos · Archived. Add Variant… takes the code
| (1–10 digits), one value for each of the product's variant attributes - or a new one, "New value…" -,
| the details of each "details only" attribute - text in both languages, or a number with its unit -,
| the weight and size; it goes last. On
| a row: Edit… (its code only while the product is a draft - a ready product's code is **corrected**,
| amendment 3(c)), Correct Code…, Photos… (up to 10), Move to Top, Move to Bottom, Archive or Restore,
| Delete… (a draft's variant only).
*/

// The dialog keeps the variant's id only: the variant is read from the page as it is now, so a dialog
// open across a save shows - and sends - what the server answered, not what it opened with.
type Dialog = { action: 'add' | 'edit' | 'code' | 'photos' | 'delete'; variantId: string | null } | null;

/** The table's one drag group. */
const VARIANTS = 'variants';

export function VariantsTab({ page }: { page: ProductPage }) {
    const { product, mayUpdate } = page;
    const t = useTranslator();
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const variants = page.variants ?? [];
    const attributes = page.attributes ?? [];
    const setAttributes = product.variantAttributeIds.map((id) => attributes.find((attribute) => attribute.id === id)).filter((attribute): attribute is AttributeChoiceData => attribute !== undefined);
    const reason = mayUpdate ? undefined : t('catalog::admin_products.read_only');
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    // The variants in their new order (P29): dropped, the whole of it as the table shows it; moved to
    // the top, that one first and the rest after it as they stand then.
    const { order, move, toTop, toBottom } = useSavedOrder(
        variants.map((variant) => variant.id),
        `/admin/products/${product.id}/variants/order`,
        'variant_ids',
    );
    const byId = new Map(variants.map((variant) => [variant.id, variant]));
    const rows = order.flatMap((id) => byId.get(id) ?? []);
    const chosen = dialog?.variantId == null ? null : (variants.find((variant) => variant.id === dialog.variantId) ?? null);
    // With no variant attributes every variant has the same values - none - so a product has one variant only.
    const addReason = reason ?? (product.variantAttributeIds.length === 0 && variants.length > 0 ? t('catalog::admin_products.variants.one_only') : undefined);

    useEffect(() => {
        // A variant gone from the page (deleted) closes its dialog.
        if (dialog?.variantId != null && chosen === null) {
            setDialog(null);
        }
    }, [dialog, chosen]);

    return (
        <Card className="material-base border-0">
            <CardContent className="grid gap-4 pt-5">
                <AttributesPanel page={page} reason={reason} />
                <Separator />
                <div className="flex flex-wrap items-center justify-end gap-3">
                    <ActionButton
                        type="button"
                        disabledReason={addReason}
                        onClick={(event) => {
                            opener.current = event.currentTarget;
                            setDialog({ action: 'add', variantId: null });
                        }}
                        data-test="add-variant"
                    >
                        {t('catalog::admin_products.variants.add')}
                    </ActionButton>
                </div>

                {variants.length === 0 ? (
                    <Empty className="border-0 p-0" data-test="variants-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_products.variants.empty_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_products.variants.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <SortableGroups groups={{ [VARIANTS]: order }} labelOf={(id) => byId.get(id)?.code ?? ''} onMove={(_, ids) => move(ids)}>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('catalog::admin_products.tab.variants')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead className="w-10">
                                        <span className="sr-only">{t('catalog::admin_products.variants.order_title')}</span>
                                    </TableHead>
                                    <TableHead>{t('catalog::admin_products.variants.column.code')}</TableHead>
                                    {setAttributes.map((attribute) => (
                                        <TableHead key={attribute.id}>{nameIn(locale, attribute.nameAr, attribute.nameEn)}</TableHead>
                                    ))}
                                    <TableHead>{t('catalog::admin_products.variants.column.details')}</TableHead>
                                    <TableHead>{t('catalog::admin_products.variants.column.measures')}</TableHead>
                                    <TableHead className="text-end">{t('catalog::admin_products.variants.column.photos')}</TableHead>
                                    <TableHead>{t('catalog::admin_products.variants.column.archived')}</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <SortableGroup id={VARIANTS} ids={order}>
                                    {rows.map((variant, index) => (
                                        <Row
                                            key={variant.id}
                                            index={index}
                                            page={page}
                                            variant={variant}
                                            attributes={attributes}
                                            held={setAttributes}
                                            reason={reason}
                                            first={index === 0}
                                            last={index === rows.length - 1}
                                            toTop={() => toTop(variant.id)}
                                            toBottom={() => toBottom(variant.id)}
                                            open={(action, trigger) => {
                                                opener.current = trigger;
                                                setDialog({ action, variantId: variant.id });
                                            }}
                                        />
                                    ))}
                                </SortableGroup>
                            </TableBody>
                        </Table>
                    </div>
                    {variants.length > 1 && reason === undefined ? <p className="text-copy-13 text-ink-muted">{t('catalog::admin_products.variants.order_body')}</p> : null}
                    </SortableGroups>
                )}
            </CardContent>

            {/* Loaded when one is first opened: none is on the page's first paint (lesson 180). */}
            <Suspense fallback={null}>
                {dialog?.action === 'add' || (dialog?.action === 'edit' && chosen !== null) ? (
                    <VariantDialog page={page} variant={chosen} setAttributes={setAttributes} attributes={attributes} open onOpenChange={close} returnFocusTo={opener} />
                ) : null}
                {dialog?.action === 'code' && chosen !== null ? <CodeDialog productId={product.id} variant={chosen} open onOpenChange={close} returnFocusTo={opener} /> : null}
                {dialog?.action === 'photos' && chosen !== null ? <VariantPhotosDialog page={page} variant={chosen} reason={reason} onOpenChange={close} returnFocusTo={opener} /> : null}
                {dialog?.action === 'delete' && chosen !== null ? <DeleteVariantDialog productId={product.id} variant={chosen} open onOpenChange={close} returnFocusTo={opener} /> : null}
            </Suspense>
        </Card>
    );
}

function Row({
    index,
    page,
    variant,
    attributes,
    held,
    reason,
    first,
    last,
    toTop,
    toBottom,
    open,
}: {
    index: number;
    page: ProductPage;
    variant: VariantData;
    attributes: AttributeChoiceData[];
    /** The product's variant attributes, a column each. */
    held: AttributeChoiceData[];
    reason: string | undefined;
    first: boolean;
    last: boolean;
    toTop: () => void;
    toBottom: () => void;
    open: (action: 'edit' | 'code' | 'photos' | 'delete', trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    // Picked up only by someone who may change the product.
    const row = useSortableRow(variant.id, reason !== undefined);
    const ready = page.product.stage !== 'DRAFT';
    const post = (path: string) => router.post(`/admin/products/${page.product.id}/variants/${variant.id}/${path}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const attributeNamed = (id: string) => attributes.find((attribute) => attribute.id === id);
    const measures = [variant.lengthMm, variant.widthMm, variant.heightMm].filter((value): value is number => value !== null);
    const sizes = [
        measures.length === 0 ? null : t('catalog::admin_products.variants.millimetres', { value: measures.join(' × ') }),
        variant.weightGrams === null ? null : t('catalog::admin_products.variants.grams', { value: String(variant.weightGrams) }),
    ].filter((part): part is string => part !== null);

    return (
        <TableRow
            ref={row.ref}
            style={row.style}
            data-dragging={row.dragging || undefined}
            className="relative z-0 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            data-test={`variant-${variant.id}`}
        >
            <TableCell>
                <DragHandle handle={row.handle} label={variant.code} reason={reason} test={`variant-drag-${index}`} />
            </TableCell>
            <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                {variant.code}
            </TableCell>
            {held.map((attribute) => {
                const value = variant.values.find((one) => one.attributeId === attribute.id);

                return (
                    <TableCell key={attribute.id}>
                        {value === undefined ? (
                            '—'
                        ) : (
                            <span className="flex items-center gap-1">
                                {/* A swatch is data: the colour staff chose, drawn as it is. */}
                                {value.swatch !== null ? <span className="size-3 rounded-sm border border-line" style={{ backgroundColor: value.swatch }} aria-hidden="true" /> : null}
                                {nameIn(locale, value.nameAr, value.nameEn)}
                            </span>
                        )}
                    </TableCell>
                );
            })}
            <TableCell className="text-copy-13">
                {variant.details.length === 0
                    ? '—'
                    : variant.details
                          .map((detail) => {
                              const attribute = attributeNamed(detail.attributeId);
                              const name = attribute === undefined ? '' : nameIn(locale, attribute.nameAr, attribute.nameEn);
                              const value = detail.number !== null ? `${detail.number}${attribute?.unitEn ? ` ${locale === 'ar' ? attribute.unitAr : attribute.unitEn}` : ''}` : nameIn(locale, detail.textAr, detail.textEn);

                              return `${name}: ${value}`;
                          })
                          .join(' · ')}
            </TableCell>
            <TableCell className="tw-figure text-copy-13" data-test="variant-sizes">
                {sizes.length === 0
                    ? '—'
                    : sizes.map((part, index) => (
                          <Fragment key={part}>
                              {index > 0 ? ' · ' : null}
                              <bdi>{part}</bdi>
                          </Fragment>
                      ))}
            </TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, variant.photos.length)}</TableCell>
            <TableCell>{variant.archived ? <Badge className={tone('amber-subtle')}>{t('catalog::admin_products.variants.archived')}</Badge> : null}</TableCell>
            <TableCell className="text-end">
                {reason !== undefined && !page.mayCorrectCode ? (
                    <MoreButtonOff name={variant.code} reason={reason} />
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={variant.code} busy={busy} data-test={`variant-actions-${variant.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            {reason === undefined ? (
                                <>
                                    <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-variant">
                                        {t('catalog::admin_products.variants.edit')}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={() => open('photos', more.current)} data-test="variant-photos">
                                        {t('catalog::admin_products.variants.photos')}
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                            {ready && page.mayCorrectCode ? (
                                <DropdownMenuItem onSelect={() => open('code', more.current)} data-test="correct-code">
                                    {t('catalog::admin_products.variants.correct')}
                                </DropdownMenuItem>
                            ) : null}
                            {reason === undefined ? (
                                <>
                                    <DropdownMenuSeparator />
                                    <MoveItems first={first} last={last} onTop={toTop} onBottom={toBottom} testPrefix="variant" />
                                </>
                            ) : null}
                            {reason === undefined ? (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem disabled={busy} onSelect={() => post(variant.archived ? 'restore' : 'archive')} data-test={variant.archived ? 'restore-variant' : 'archive-variant'}>
                                        {variant.archived ? t('catalog::admin_products.variants.restore') : t('catalog::admin_products.variants.archive')}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        aria-disabled={ready || undefined}
                                        className={ready ? 'cursor-not-allowed' : undefined}
                                        onSelect={(event) => {
                                            if (ready) {
                                                event.preventDefault();

                                                return;
                                            }

                                            open('delete', more.current);
                                        }}
                                        data-test="delete-variant"
                                    >
                                        <span className="grid gap-0.5">
                                            <span className={ready ? 'opacity-60' : undefined}>{t('catalog::admin_products.variants.delete')}</span>
                                            {ready ? <span className="text-copy-12 text-ink-muted">{t('catalog::admin_products.variants.delete_reason')}</span> : null}
                                        </span>
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </TableCell>
        </TableRow>
    );
}

/** The Variants tab's page: this product above its tabs, this one open (ProductShell). */
export default function VariantsTabPage(page: ProductPage) {
    return (
        <ProductShell page={page}>
            <VariantsTab page={page} />
        </ProductShell>
    );
}
