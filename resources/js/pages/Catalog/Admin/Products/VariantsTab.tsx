import { Fragment, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { type Box, useChecks } from '@/lib/use-checks';
import type { AttributeChoiceData, ProductPage, VariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, MoreButtonOff, nameIn, useLocale } from '../parts';
import { PhotoGrid } from './photos';
import { ProductShell } from './shell';

/*
| A product's variants (catalog.md §4.4 S9): # · code · its values · details · weight and size · photos ·
| Archived. Add Variant… takes the code (1–10 digits), one value for each attribute of the variation,
| the details of each "details only" attribute - text in both languages, or a number with its unit -,
| the weight and size, the place. On a row: Edit… (its code only while the product is a draft - a
| ready product's code is **corrected**, amendment 3(c)), Correct Code…, Photos… (up to 10), Archive
| or Restore, Delete… (a draft's variant only).
*/

// The dialog keeps the variant's id only: the variant is read from the page as it is now, so a dialog
// open across a save shows - and sends - what the server answered, not what it opened with.
type Dialog = { action: 'add' | 'edit' | 'code' | 'photos' | 'delete'; variantId: string | null } | null;

export function VariantsTab({ page }: { page: ProductPage }) {
    const { product, mayUpdate } = page;
    const t = useTranslator();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const variants = page.variants ?? [];
    const attributes = page.attributes ?? [];
    const setAttributes = product.setAttributeIds.map((id) => attributes.find((attribute) => attribute.id === id)).filter((attribute): attribute is AttributeChoiceData => attribute !== undefined);
    const reason = mayUpdate ? undefined : t('catalog::admin_products.read_only');
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const nextPosition = variants.reduce((highest, variant) => Math.max(highest, variant.position), 0) + 10;
    const chosen = dialog?.variantId == null ? null : (variants.find((variant) => variant.id === dialog.variantId) ?? null);
    // With no variation every variant has the same values - none - so a product has one variant only.
    const addReason = reason ?? (product.attributeSetId === null && variants.length > 0 ? t('catalog::admin_products.variants.one_only') : undefined);

    useEffect(() => {
        // A variant gone from the page (deleted) closes its dialog.
        if (dialog?.variantId != null && chosen === null) {
            setDialog(null);
        }
    }, [dialog, chosen]);

    return (
        <Card className="material-base border-0">
            <CardContent className="grid gap-4 pt-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    {product.attributeSetId === null && variants.length === 0 ? <Note data-test="no-variation">{t('catalog::admin_products.variants.no_variation')}</Note> : <span />}
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
                    <div className="overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('catalog::admin_products.tab.variants')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead className="w-12">
                                        <span aria-hidden="true">#</span>
                                        <span className="sr-only">{t('catalog::admin_products.variants.position')}</span>
                                    </TableHead>
                                    <TableHead>{t('catalog::admin_products.variants.column.code')}</TableHead>
                                    <TableHead>{t('catalog::admin_products.variants.column.values')}</TableHead>
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
                                {variants.map((variant) => (
                                    <Row
                                        key={variant.id}
                                        page={page}
                                        variant={variant}
                                        attributes={attributes}
                                        reason={reason}
                                        open={(action, trigger) => {
                                            opener.current = trigger;
                                            setDialog({ action, variantId: variant.id });
                                        }}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>

            {dialog?.action === 'add' || (dialog?.action === 'edit' && chosen !== null) ? (
                <VariantDialog page={page} variant={chosen} setAttributes={setAttributes} attributes={attributes} nextPosition={nextPosition} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
            {dialog?.action === 'code' && chosen !== null ? <CodeDialog productId={product.id} variant={chosen} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'photos' && chosen !== null ? (
                <PanelDialog
                    wide
                    open
                    onOpenChange={close}
                    returnFocusTo={opener}
                    title={t('catalog::admin_products.variants.photos_title', { code: chosen.code })}
                    description={t('catalog::admin_products.variants.photos_body')}
                    busy={false}
                    confirm={null}
                >
                    <PhotoGrid
                        photos={chosen.photos}
                        url={`/admin/products/${product.id}/variants/${chosen.id}/photos`}
                        max={10}
                        helper={t('catalog::admin_products.photos.variant_helper')}
                        reason={reason}
                        testPrefix="variant-photos"
                    />
                </PanelDialog>
            ) : null}
            {dialog?.action === 'delete' && chosen !== null ? <DeleteVariantDialog productId={product.id} variant={chosen} open onOpenChange={close} returnFocusTo={opener} /> : null}
        </Card>
    );
}

function Row({
    page,
    variant,
    attributes,
    reason,
    open,
}: {
    page: ProductPage;
    variant: VariantData;
    attributes: AttributeChoiceData[];
    reason: string | undefined;
    open: (action: 'edit' | 'code' | 'photos' | 'delete', trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const ready = page.product.stage !== 'DRAFT';
    const post = (path: string) => router.post(`/admin/products/${page.product.id}/variants/${variant.id}/${path}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const attributeNamed = (id: string) => attributes.find((attribute) => attribute.id === id);
    const measures = [variant.lengthMm, variant.widthMm, variant.heightMm].filter((value): value is number => value !== null);
    const sizes = [
        measures.length === 0 ? null : t('catalog::admin_products.variants.millimetres', { value: measures.join(' × ') }),
        variant.weightGrams === null ? null : t('catalog::admin_products.variants.grams', { value: String(variant.weightGrams) }),
    ].filter((part): part is string => part !== null);

    return (
        <TableRow data-test={`variant-${variant.id}`}>
            <TableCell className="tw-figure text-ink-muted">{figure(locale, variant.position)}</TableCell>
            <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                {variant.code}
            </TableCell>
            <TableCell>
                <span className="flex flex-wrap items-center gap-2">
                    {variant.values.map((value) => (
                        <span key={value.attributeId} className="flex items-center gap-1">
                            {/* A swatch is data: the colour staff chose, drawn as it is. */}
                            {value.swatch !== null ? <span className="size-3 rounded-sm border border-line" style={{ backgroundColor: value.swatch }} aria-hidden="true" /> : null}
                            {nameIn(locale, value.nameAr, value.nameEn)}
                        </span>
                    ))}
                </span>
            </TableCell>
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

type Detail = { text_ar: string; text_en: string; number: string };

type VariantForm = {
    code: string;
    values: Record<string, string>;
    details: Record<string, Detail>;
    weight_grams: string;
    length_mm: string;
    width_mm: string;
    height_mm: string;
    position: string;
};

/** Add a variant, or edit one: its code (a draft's only), values, details, weight and size, place. */
function VariantDialog({
    page,
    variant,
    setAttributes,
    attributes,
    nextPosition,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    page: ProductPage;
    variant: VariantData | null;
    setAttributes: AttributeChoiceData[];
    attributes: AttributeChoiceData[];
    nextPosition: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const codeLocked = variant !== null && page.product.stage !== 'DRAFT';
    // "Details only" attributes: the active ones, and any the variant already carries.
    const detailAttributes = attributes.filter((attribute) => attribute.kind === 'INFORMATIONAL' && (attribute.active || variant?.details.some((detail) => detail.attributeId === attribute.id)));
    const initial = (): VariantForm => ({
        code: variant?.code ?? '',
        values: Object.fromEntries(setAttributes.map((attribute) => [attribute.id, variant?.values.find((value) => value.attributeId === attribute.id)?.valueId ?? ''])),
        details: Object.fromEntries(
            detailAttributes.map((attribute) => {
                const held = variant?.details.find((detail) => detail.attributeId === attribute.id);

                return [attribute.id, { text_ar: held?.textAr ?? '', text_en: held?.textEn ?? '', number: held?.number ?? '' }];
            }),
        ),
        weight_grams: variant?.weightGrams === null || variant === null ? '' : String(variant.weightGrams),
        length_mm: variant?.lengthMm === null || variant === null ? '' : String(variant.lengthMm),
        width_mm: variant?.widthMm === null || variant === null ? '' : String(variant.widthMm),
        height_mm: variant?.heightMm === null || variant === null ? '' : String(variant.heightMm),
        position: String(variant?.position ?? nextPosition),
    });
    const form = useForm<VariantForm>(initial());

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
        }
    }, [open, variant?.id]);

    // Each box as typed (frontend.md §1.7), with the domain's rules: a code of 1 to 10 digits
    // (ProductCode, while it may still change); a value for each of the product's variant attributes;
    // a detail's number with at most nine digits before the point and three after, or its words of up
    // to 200 characters in each language (VariantDetail - that a text is given in both languages is
    // the server's to say); weight and sizes whole numbers from 1 to 1,000,000, each optional
    // (VariantMeasures); a position from 0 to 10,000 (ListPosition). Catalog's request reads an empty
    // or broken number as -1, so the position is required.
    const measure = { number: { min: 1, max: 1_000_000 } };
    const checks = useChecks(
        [
            { id: 'variant-code', label: t('catalog::admin_products.variants.code'), value: form.data.code, rules: { required: true, digits: true, length: { max: 10 } }, off: codeLocked },
            ...setAttributes.map(
                (attribute): Box => ({ id: `variant-value-${attribute.id}`, label: nameIn(locale, attribute.nameAr, attribute.nameEn), value: form.data.values[attribute.id] ?? '', rules: { required: true } }),
            ),
            ...detailAttributes.flatMap((attribute): Box[] => {
                const name = nameIn(locale, attribute.nameAr, attribute.nameEn);
                const detail = form.data.details[attribute.id] ?? { text_ar: '', text_en: '', number: '' };
                const text = { length: { max: 200 } };

                return attribute.unitEn !== null
                    ? [
                          {
                              id: `variant-detail-${attribute.id}`,
                              label: `${t('catalog::admin_products.variants.number', { name })} (${locale === 'ar' ? attribute.unitAr : attribute.unitEn})`,
                              subject: t('catalog::admin_products.variants.number', { name }),
                              value: detail.number,
                              rules: { number: { decimals: 3, min: -999_999_999.999, max: 999_999_999.999 } },
                          },
                      ]
                    : [
                          { id: `variant-detail-ar-${attribute.id}`, label: t('catalog::admin_products.variants.text_ar', { name }), value: detail.text_ar, rules: text },
                          { id: `variant-detail-en-${attribute.id}`, label: t('catalog::admin_products.variants.text_en', { name }), value: detail.text_en, rules: text },
                      ];
            }),
            { id: 'variant-weight_grams', label: t('catalog::admin_products.variants.weight'), value: form.data.weight_grams, rules: measure },
            { id: 'variant-length_mm', label: t('catalog::admin_products.variants.length'), value: form.data.length_mm, rules: measure },
            { id: 'variant-width_mm', label: t('catalog::admin_products.variants.width'), value: form.data.width_mm, rules: measure },
            { id: 'variant-height_mm', label: t('catalog::admin_products.variants.height'), value: form.data.height_mm, rules: measure },
            { id: 'variant-position', label: t('catalog::admin_products.variants.position'), value: form.data.position, rules: { required: true, number: { min: 0, max: 10000 } } },
        ],
        open,
    );

    function submit() {
        // Only the details given are sent: an attribute left empty is no detail.
        form.transform((data) => ({
            ...data,
            details: Object.fromEntries(
                Object.entries(data.details)
                    .filter(([, detail]) => detail.number.trim() !== '' || detail.text_ar.trim() !== '' || detail.text_en.trim() !== '')
                    .map(([id, detail]) => [id, detail.number.trim() !== '' ? { number: detail.number.trim() } : { text_ar: detail.text_ar, text_en: detail.text_en }]),
            ),
        }));
        checks.submit(() =>
            form.post(variant === null ? `/admin/products/${page.product.id}/variants` : `/admin/products/${page.product.id}/variants/${variant.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }),
        );
    }

    const errors = form.errors as Record<string, string | undefined>;
    const number = (field: 'weight_grams' | 'length_mm' | 'width_mm' | 'height_mm' | 'position', label: string, helper?: string) => (
        <TextField
            id={`variant-${field}`}
            dir="ltr"
            inputMode="numeric"
            inputClassName="tw-figure"
            label={label}
            helper={helper}
            value={form.data[field]}
            check={checks.box(`variant-${field}`, errors[field])}
            onChange={(event) => form.setData(field, toLatinDigits(event.target.value))}
        />
    );

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={variant === null ? t('catalog::admin_products.variants.add_title') : t('catalog::admin_products.variants.edit_title')}
            description={t('catalog::admin_products.variants.empty_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-variant">
                    {variant === null ? t('catalog::admin_products.variants.add_title') : t('catalog::admin_products.variants.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField
                    id="variant-code"
                    dir="ltr"
                    inputMode="numeric"
                    inputClassName="tw-figure font-mono"
                    className="max-w-56"
                    disabled={codeLocked}
                    label={t('catalog::admin_products.variants.code')}
                    helper={codeLocked ? t('catalog::admin_products.variants.code_locked') : t('catalog::admin_products.variants.code_helper')}
                    value={form.data.code}
                    check={checks.box('variant-code', errors.code)}
                    onChange={(event) => form.setData('code', toLatinDigits(event.target.value))}
                    data-test="variant-code"
                />

                {setAttributes.length > 0 ? (
                    <FieldSet>
                        <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_products.variants.values')}</FieldLegend>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {setAttributes.map((attribute) => (
                                <SelectField
                                    key={attribute.id}
                                    id={`variant-value-${attribute.id}`}
                                    label={nameIn(locale, attribute.nameAr, attribute.nameEn)}
                                    value={form.data.values[attribute.id] ?? ''}
                                    check={checks.box(`variant-value-${attribute.id}`)}
                                    onChange={(event) => form.setData('values', { ...form.data.values, [attribute.id]: event.target.value })}
                                    data-test={`variant-value-${attribute.id}`}
                                >
                                    <NativeSelectOption value="">{t('catalog::admin.choose')}</NativeSelectOption>
                                    {attribute.values
                                        .filter((value) => value.active || value.id === form.data.values[attribute.id])
                                        .map((value) => (
                                            <NativeSelectOption key={value.id} value={value.id}>
                                                {nameIn(locale, value.nameAr, value.nameEn)}
                                            </NativeSelectOption>
                                        ))}
                                </SelectField>
                            ))}
                        </div>
                        {errors.values ? <FieldError>{errors.values}</FieldError> : null}
                    </FieldSet>
                ) : null}

                {detailAttributes.length > 0 ? (
                    <FieldSet>
                        <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_products.variants.details')}</FieldLegend>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {detailAttributes.map((attribute) => {
                                const name = nameIn(locale, attribute.nameAr, attribute.nameEn);
                                const detail = form.data.details[attribute.id] ?? { text_ar: '', text_en: '', number: '' };
                                const set = (next: Partial<Detail>) => form.setData('details', { ...form.data.details, [attribute.id]: { ...detail, ...next } });

                                // An attribute with a unit takes a number; another, text in both languages.
                                return attribute.unitEn !== null ? (
                                    <TextField
                                        key={attribute.id}
                                        id={`variant-detail-${attribute.id}`}
                                        dir="ltr"
                                        inputMode="decimal"
                                        inputClassName="tw-figure"
                                        label={`${t('catalog::admin_products.variants.number', { name })} (${locale === 'ar' ? attribute.unitAr : attribute.unitEn})`}
                                        value={detail.number}
                                        check={checks.box(`variant-detail-${attribute.id}`)}
                                        onChange={(event) => set({ number: toLatinDigits(event.target.value) })}
                                    />
                                ) : (
                                    <div key={attribute.id} className="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                                        <TextField id={`variant-detail-ar-${attribute.id}`} dir="rtl" label={t('catalog::admin_products.variants.text_ar', { name })} value={detail.text_ar} check={checks.box(`variant-detail-ar-${attribute.id}`)} onChange={(event) => set({ text_ar: event.target.value })} />
                                        <TextField id={`variant-detail-en-${attribute.id}`} dir="ltr" label={t('catalog::admin_products.variants.text_en', { name })} value={detail.text_en} check={checks.box(`variant-detail-en-${attribute.id}`)} onChange={(event) => set({ text_en: event.target.value })} />
                                    </div>
                                );
                            })}
                        </div>
                        {errors.details || errors.number ? <FieldError>{errors.details ?? errors.number}</FieldError> : null}
                    </FieldSet>
                ) : null}

                <FieldSet>
                    <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_products.variants.measures')}</FieldLegend>
                    <div className="grid gap-4 sm:grid-cols-4">
                        {number('weight_grams', t('catalog::admin_products.variants.weight'))}
                        {number('length_mm', t('catalog::admin_products.variants.length'))}
                        {number('width_mm', t('catalog::admin_products.variants.width'))}
                        {number('height_mm', t('catalog::admin_products.variants.height'))}
                    </div>
                    <p className="text-copy-13 text-ink-muted">{t('catalog::admin_products.variants.measure_helper')}</p>
                </FieldSet>

                <div className="max-w-40">{number('position', t('catalog::admin_products.variants.position'), t('catalog::admin.field.position_helper'))}</div>
            </div>
        </PanelDialog>
    );
}

/** A ready product's code corrected - on every variant holding it (amendment 3(c)). */
function CodeDialog({ productId, variant, open, onOpenChange, returnFocusTo }: { productId: string; variant: VariantData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const form = useForm({ code: '' });
    // The new code as typed (frontend.md §1.7): 1 to 10 digits (ProductCode). Afresh each time the
    // dialog opens.
    const checks = useChecks([{ id: 'correct-code', label: t('catalog::admin_products.variants.code'), value: form.data.code, rules: { required: true, digits: true, length: { max: 10 } } }], open);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_products.variants.correct_title', { code: variant.code })}
            description={t('catalog::admin_products.variants.correct_body')}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={checks.reason}
                    onClick={() => checks.submit(() => form.post(`/admin/products/${productId}/variants/${variant.id}/code`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }))}
                    data-test="confirm-code"
                >
                    {t('catalog::admin_products.variants.correct_confirm')}
                </ActionButton>
            }
        >
            <TextField
                id="correct-code"
                dir="ltr"
                inputMode="numeric"
                inputClassName="tw-figure font-mono"
                label={t('catalog::admin_products.variants.code')}
                helper={t('catalog::admin_products.variants.code_helper')}
                value={form.data.code}
                check={checks.box('correct-code', form.errors.code)}
                onChange={(event) => form.setData('code', toLatinDigits(event.target.value))}
            />
        </PanelDialog>
    );
}

function DeleteVariantDialog({ productId, variant, open, onOpenChange, returnFocusTo }: { productId: string; variant: VariantData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_products.variants.delete_title')}
            description={t('catalog::admin_products.variants.delete_body', { code: variant.code })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        router.post(`/admin/products/${productId}/variants/${variant.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })
                    }
                    data-test="confirm-delete-variant"
                >
                    {t('catalog::admin_products.variants.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
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
