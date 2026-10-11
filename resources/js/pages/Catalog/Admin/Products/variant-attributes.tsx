import { lazy, Suspense, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { useTranslator } from '@/lib/t';
import type { AttributeChoiceData, ProductPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, nameIn, useLocale } from '../parts';
import { SortableList } from '../SortableList';

// Loaded when one is first opened: none is on the page's first paint (lesson 180).
const AddAttributeDialog = lazy(() => import('./variant-dialogs').then((module) => ({ default: module.AddAttributeDialog })));
const RemoveDialog = lazy(() => import('./variant-dialogs').then((module) => ({ default: module.RemoveDialog })));

/*
| Has Variants (catalog.md §1.7, §4.4 S9; owner, 2026-10-09 and 2026-10-10, amendment 16(b)-(d)): a
| switch, Yes while the product's variants are made of at least one attribute - any of the library's
| variant-making attributes, staff choosing. With Yes, its attributes in their order, dragged or moved
| to the top or bottom from each one's menu; Add Attribute… asks each variant's value of it, archived
| ones too; Remove… gives them up, refused while two variants would then be alike; switching to No
| removes them all, once one variant is left. "New value…" makes a value from here (P28).
*/

const base = (page: ProductPage) => `/admin/products/${page.product.id}`;

/** A product's variants are made of at most this many attributes (AddVariantAttributeHandler). */
const MAX_ATTRIBUTES = 10;

export function AttributesPanel({ page, reason }: { page: ProductPage; reason: string | undefined }) {
    const t = useTranslator();
    const locale = useLocale();
    const [dialog, setDialog] = useState<{ action: 'add' | 'remove' | 'off'; attributeId: string | null } | null>(null);
    const [busy, setBusy] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const switcher = useRef<HTMLButtonElement | null>(null);
    // Each attribute's ⋯ button: focus goes back to it when its Remove… dialog closes.
    const triggers = useRef(new Map<string, HTMLButtonElement>());
    const variants = page.variants ?? [];
    const attributes = page.attributes ?? [];
    const held = page.product.variantAttributeIds.map((id) => attributes.find((attribute) => attribute.id === id)).filter((attribute): attribute is AttributeChoiceData => attribute !== undefined);
    const yes = held.length > 0;
    const offered = attributes.filter((attribute) => attribute.kind === 'VARIANT' && attribute.active && !held.some((other) => other.id === attribute.id));
    const addReason =
        reason ??
        (held.length >= MAX_ATTRIBUTES ? t('catalog::admin_products.variants.attributes_full', { max: String(MAX_ATTRIBUTES) }) : offered.length === 0 ? t('catalog::admin_products.variants.attributes_none') : undefined);
    const offReason = reason ?? (yes && variants.length > 1 ? t('catalog::admin_products.variants.has_variants_off_reason') : undefined);
    const order = (ids: string[]) => router.post(`${base(page)}/attributes/order`, { attribute_ids: ids }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const removed = held.find((attribute) => attribute.id === dialog?.attributeId) ?? null;

    return (
        <div className="grid gap-3" data-test="has-variants">
            <div className="flex flex-wrap items-start gap-3">
                <Switch
                    ref={switcher}
                    id="has-variants"
                    checked={yes}
                    disabled={yes ? offReason !== undefined || busy : addReason !== undefined}
                    aria-describedby="has-variants-sentence"
                    onCheckedChange={(checked) => {
                        opener.current = switcher.current;
                        setDialog({ action: checked ? 'add' : 'off', attributeId: null });
                    }}
                    data-test="has-variants-switch"
                />
                <div className="grid gap-0.5">
                    <label htmlFor="has-variants" className="text-label-14 text-ink">
                        {t('catalog::admin_products.variants.has_variants')}
                    </label>
                    <p id="has-variants-sentence" className="text-copy-13 text-ink-muted">
                        {yes ? t('catalog::admin_products.variants.has_variants_yes') : t('catalog::admin_products.variants.has_variants_no')}
                        {yes && offReason !== undefined && reason === undefined ? ` ${offReason}` : null}
                        {!yes && addReason !== undefined && reason === undefined ? ` ${addReason}` : null}
                    </p>
                </div>
            </div>

            {yes ? (
                <div className="grid gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-label-14 text-ink">{t('catalog::admin_products.variants.attributes')}</span>
                        <ActionButton
                            type="button"
                            variant="secondary"
                            disabledReason={addReason}
                            onClick={(event) => {
                                opener.current = event.currentTarget;
                                setDialog({ action: 'add', attributeId: null });
                            }}
                            data-test="add-attribute"
                        >
                            {t('catalog::admin_products.variants.add_attribute')}
                        </ActionButton>
                    </div>
                    <SortableList
                        testPrefix="variant-attribute"
                        disabled={reason !== undefined || busy}
                        onChange={order}
                        items={held.map((attribute, index) => ({
                            id: attribute.id,
                            label: nameIn(locale, attribute.nameAr, attribute.nameEn),
                            extra:
                                reason === undefined ? (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <MoreButton
                                                ref={(node) => {
                                                    if (node === null) {
                                                        triggers.current.delete(attribute.id);
                                                    } else {
                                                        triggers.current.set(attribute.id, node);
                                                    }
                                                }}
                                                name={nameIn(locale, attribute.nameAr, attribute.nameEn)}
                                                busy={busy}
                                                data-test={`attribute-actions-${attribute.id}`}
                                            />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" className="min-w-48">
                                            <DropdownMenuItem disabled={index === 0 || busy} onSelect={() => order([attribute.id, ...held.filter((other) => other.id !== attribute.id).map((other) => other.id)])} data-test="attribute-top">
                                                {t('catalog::admin_products.variants.move_top')}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem disabled={index === held.length - 1 || busy} onSelect={() => order([...held.filter((other) => other.id !== attribute.id).map((other) => other.id), attribute.id])} data-test="attribute-bottom">
                                                {t('catalog::admin_products.variants.move_bottom')}
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() => {
                                                    opener.current = triggers.current.get(attribute.id) ?? null;
                                                    setDialog({ action: 'remove', attributeId: attribute.id });
                                                }}
                                                data-test="remove-attribute"
                                            >
                                                {t('catalog::admin_products.variants.remove_attribute')}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                ) : undefined,
                        }))}
                    />
                    <p className="text-copy-13 text-ink-muted">{t('catalog::admin_products.variants.attributes_helper')}</p>
                </div>
            ) : null}

            <Suspense fallback={null}>
                {dialog?.action === 'add' ? <AddAttributeDialog page={page} variants={variants} held={held} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'remove' && removed !== null ? (
                <RemoveDialog page={page} attributes={[removed]} title={t('catalog::admin_products.variants.remove_attribute_title', { name: nameIn(locale, removed.nameAr, removed.nameEn) })} body={t('catalog::admin_products.variants.remove_attribute_body', { name: nameIn(locale, removed.nameAr, removed.nameEn) })} confirm={t('catalog::admin_products.variants.remove_attribute_title', { name: nameIn(locale, removed.nameAr, removed.nameEn) })} onOpenChange={close} returnFocusTo={opener} />
            ) : null}
            {dialog?.action === 'off' ? <RemoveDialog page={page} attributes={[...held].reverse()} title={t('catalog::admin_products.variants.turn_off_title')} body={t('catalog::admin_products.variants.turn_off_body')} confirm={t('catalog::admin_products.variants.turn_off_confirm')} onOpenChange={close} returnFocusTo={opener} /> : null}
            </Suspense>
        </div>
    );
}
