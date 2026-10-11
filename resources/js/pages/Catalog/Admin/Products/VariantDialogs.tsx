import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { type Box, useChecks } from '@/lib/use-checks';
import type { AttributeChoiceData, ProductPage, VariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';

/*
| The Variants tab's dialogs that take what is typed - adding or editing a variant, correcting a code
| (catalog.md §4.4 S9) - each box checked as it is typed (frontend.md §1.7). In a file of their own,
| loaded when one is opened rather than with the tab, which keeps the tab within its page budget
| (frontend.md §5: 60 KB a page).
*/

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
export function VariantDialog({
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
export function CodeDialog({ productId, variant, open, onOpenChange, returnFocusTo }: { productId: string; variant: VariantData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
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
