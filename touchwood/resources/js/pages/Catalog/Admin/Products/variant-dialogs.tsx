import { useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { checked, describedBy, Messages, SelectField, TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelectOption } from '@/components/ui/native-select';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { type Box, type BoxCheck, useChecks } from '@/lib/use-checks';
import type { AttributeChoiceData, ProductPage, VariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';
import { PhotoGrid } from './photos';

/*
| The Variants tab's dialogs (catalog.md §4.4 S9, amendment 16(b)-(d)), kept apart from the tab and its
| Has Variants panel and loaded the first time one opens: none is on the page's first paint, and the
| page stays within its JavaScript budget (frontend.md §5). Each box that takes what is typed checks
| itself as it is typed (frontend.md §1.7), with the domain's rules named where each dialog declares
| them.
*/

const base = (page: ProductPage) => `/admin/products/${page.product.id}`;

const SWATCH = /^#[0-9a-f]{6}$/i;

/** One more attribute: which, and each variant's value of it, archived ones too. */
export function AddAttributeDialog({
    page,
    variants,
    held,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    page: ProductPage;
    variants: VariantData[];
    held: AttributeChoiceData[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm<{ attribute_id: string; values: Record<string, string> }>({ attribute_id: '', values: {} });
    const offered = (page.attributes ?? []).filter((attribute) => attribute.kind === 'VARIANT' && attribute.active && !held.some((other) => other.id === attribute.id));
    const chosen = offered.find((attribute) => attribute.id === form.data.attribute_id) ?? null;
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_products.variants.add_attribute_title')}
            description={t('catalog::admin_products.variants.add_attribute_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={chosen === null ? t('catalog::admin_products.variants.attribute_first') : undefined} onClick={() => form.post(`${base(page)}/attributes`, { preserveScroll: true, onSuccess: () => onOpenChange(false) })} data-test="confirm-attribute">
                    {t('catalog::admin_products.variants.add_attribute_title')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <SelectField
                    id="attribute-choice"
                    label={t('catalog::admin_products.variants.attribute')}
                    value={form.data.attribute_id}
                    error={errors.attribute_id}
                    onChange={(event) => form.setData({ attribute_id: event.target.value, values: {} })}
                    data-test="attribute-choice"
                >
                    <NativeSelectOption value="">{t('catalog::admin.choose')}</NativeSelectOption>
                    {offered.map((attribute) => (
                        <NativeSelectOption key={attribute.id} value={attribute.id}>
                            {nameIn(locale, attribute.nameAr, attribute.nameEn)}
                        </NativeSelectOption>
                    ))}
                </SelectField>

                {chosen !== null ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {variants.map((variant) => (
                            <ValueField
                                key={variant.id}
                                page={page}
                                attribute={chosen}
                                id={`attribute-value-${variant.id}`}
                                label={t('catalog::admin_products.variants.value_of', { code: variant.code })}
                                value={form.data.values[variant.id] ?? ''}
                                onChange={(value) => form.setData((data) => ({ ...data, values: { ...data.values, [variant.id]: value } }))}
                            />
                        ))}
                    </div>
                ) : null}
                {errors.values ? <FieldError>{errors.values}</FieldError> : null}
            </div>
        </PanelDialog>
    );
}

/** Removes the attributes one after another: one, from its menu, or all of them, switching to No. */
export function RemoveDialog({
    page,
    attributes,
    title,
    body,
    confirm,
    onOpenChange,
    returnFocusTo,
}: {
    page: ProductPage;
    attributes: AttributeChoiceData[];
    title: string;
    body: string;
    confirm: string;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const [busy, setBusy] = useState(false);
    const remove = (left: AttributeChoiceData[]) => {
        const [first, ...rest] = left;

        if (first === undefined) {
            onOpenChange(false);

            return;
        }

        // A refusal stops here and is said inside the dialog, which stays open; each success goes on.
        router.post(`${base(page)}/attributes/${first.id}/remove`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => remove(rest) });
    };

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={body}
            busy={busy}
            confirm={
                <ActionButton variant="destructive" loading={busy} onClick={() => remove(attributes)} data-test="confirm-remove-attribute">
                    {confirm}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}

/**
 * A value of an attribute for a variant, with "New value…" beside it: the new value is made, then
 * chosen here, from the page as the server answers it.
 */
export function ValueField({
    page,
    attribute,
    id,
    label,
    value,
    onChange,
    check,
}: {
    page: ProductPage;
    attribute: AttributeChoiceData;
    id: string;
    label: string;
    value: string;
    onChange: (valueId: string) => void;
    /** The select's check as it is chosen, from its dialog's useChecks. */
    check?: BoxCheck;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [adding, setAdding] = useState(false);

    return (
        <div className="grid gap-1.5">
            <SelectField id={id} label={label} value={value} check={check} onChange={(event) => onChange(event.target.value)} data-test={id}>
                <NativeSelectOption value="">{t('catalog::admin.choose')}</NativeSelectOption>
                {attribute.values
                    .filter((choice) => choice.active || choice.id === value)
                    .map((choice) => (
                        <NativeSelectOption key={choice.id} value={choice.id}>
                            {nameIn(locale, choice.nameAr, choice.nameEn)}
                        </NativeSelectOption>
                    ))}
            </SelectField>
            {/* An attribute switched off takes no new value (ListItemInactive): none is offered. */}
            {attribute.active ? (
                <Button type="button" variant="link" size="sm" className="justify-self-start px-0" onClick={() => setAdding(true)} data-test={`${id}-new`}>
                    {t('catalog::admin_products.variants.new_value')}
                </Button>
            ) : null}
            {adding ? <NewValueDialog page={page} attribute={attribute} onMade={onChange} onOpenChange={(open) => setAdding(open)} /> : null}
        </div>
    );
}

function NewValueDialog({ page, attribute, onMade, onOpenChange }: { page: ProductPage; attribute: AttributeChoiceData; onMade: (valueId: string) => void; onOpenChange: (open: boolean) => void }) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm({ attribute_id: attribute.id, name_ar: '', name_en: '', swatch: '' });
    const name = nameIn(locale, attribute.nameAr, attribute.nameEn);
    // Each box as typed (frontend.md §1.7), as the values screen checks them: a name of up to 100
    // characters in each language (AttributeValue::NAME_MAX), and a colour's swatch, required, a colour
    // code (AttributeValue). A name another value holds is the server's to say.
    const valueName = { required: true, length: { max: 100 } };
    const checks = useChecks([
        { id: 'new-value-ar', label: t('catalog::admin_products.variants.new_value_ar'), value: form.data.name_ar, rules: valueName },
        { id: 'new-value-en', label: t('catalog::admin_products.variants.new_value_en'), value: form.data.name_en, rules: valueName },
        {
            id: 'new-value-swatch',
            label: t('catalog::admin_products.variants.new_value_swatch'),
            value: form.data.swatch,
            rules: { required: true, length: { max: 7 }, format: { pattern: SWATCH, key: 'catalog::admin_attributes.check.swatch' } },
            off: !attribute.isColour,
        },
    ]);
    const swatch = checks.box('new-value-swatch', form.errors.swatch);

    function submit() {
        const typed = form.data.name_en.trim().toLowerCase();

        checks.submit(() => form.post(`${base(page)}/values`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (visit) => {
                // The value made is found in the page the server answered, by its English name.
                const now = (visit.props.attributes as AttributeChoiceData[] | null | undefined)?.find((other) => other.id === attribute.id);
                const made = now?.values.find((choice) => choice.nameEn.trim().toLowerCase() === typed);

                if (made !== undefined) {
                    onMade(made.id);
                }

                onOpenChange(false);
            },
        }));
    }

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            title={t('catalog::admin_products.variants.new_value_title', { name })}
            description={t('catalog::admin_products.variants.new_value_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-new-value">
                    {t('catalog::admin_products.variants.new_value_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField id="new-value-ar" dir="rtl" label={t('catalog::admin_products.variants.new_value_ar')} value={form.data.name_ar} check={checks.box('new-value-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', toLatinDigits(event.target.value))} data-test="new-value-ar" />
                <TextField id="new-value-en" dir="ltr" label={t('catalog::admin_products.variants.new_value_en')} value={form.data.name_en} check={checks.box('new-value-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', toLatinDigits(event.target.value))} data-test="new-value-en" />
                {attribute.isColour ? (
                    // As the values screen takes it (Attributes/Show.tsx): the browser's own picker, holding
                    // the colour typed once it is one - no colour of ours is written into the screen.
                    <Field className="sm:col-span-2">
                        <FieldLabel htmlFor="new-value-swatch">{t('catalog::admin_products.variants.new_value_swatch')}</FieldLabel>
                        <div className="flex items-center gap-2">
                            {SWATCH.test(form.data.swatch) ? (
                                <Input key="chosen" type="color" className="h-9 w-12 p-1" aria-label={t('catalog::admin_attributes.value.swatch_pick')} value={form.data.swatch} onChange={(event) => form.setData('swatch', event.target.value)} />
                            ) : (
                                <Input key="unset" type="color" className="h-9 w-12 p-1" aria-label={t('catalog::admin_attributes.value.swatch_pick')} onChange={(event) => form.setData('swatch', event.target.value)} />
                            )}
                            {/* A shadcn Input wired to its check by hand, as the values screen wires it. */}
                            <Input
                                id="new-value-swatch"
                                dir="ltr"
                                className="tw-figure max-w-32"
                                value={form.data.swatch}
                                {...checked<HTMLInputElement>(swatch, {})}
                                aria-invalid={swatch.message ? true : undefined}
                                aria-describedby={describedBy('new-value-swatch', t('catalog::admin_attributes.value.swatch_helper'), swatch.message)}
                                onChange={(event) => {
                                    form.setData('swatch', toLatinDigits(event.target.value));
                                    swatch.onType();
                                }}
                                data-test="new-value-swatch"
                            />
                        </div>
                        <Messages id="new-value-swatch" helper={t('catalog::admin_attributes.value.swatch_helper')} error={swatch.message} check={swatch} />
                    </Field>
                ) : null}
            </div>
        </PanelDialog>
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
};

/** Add a variant (it goes last), or edit one: its code (a draft's only), values, details, weight and size. */
export function VariantDialog({
    page,
    variant,
    setAttributes,
    attributes,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    page: ProductPage;
    variant: VariantData | null;
    setAttributes: AttributeChoiceData[];
    attributes: AttributeChoiceData[];
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
    // (VariantMeasures). Afresh each time the dialog opens.
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
    const number = (field: 'weight_grams' | 'length_mm' | 'width_mm' | 'height_mm', label: string) => (
        <TextField
            id={`variant-${field}`}
            dir="ltr"
            inputMode="numeric"
            inputClassName="tw-figure"
            label={label}
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
                                <ValueField
                                    key={attribute.id}
                                    page={page}
                                    attribute={attribute}
                                    id={`variant-value-${attribute.id}`}
                                    label={nameIn(locale, attribute.nameAr, attribute.nameEn)}
                                    value={form.data.values[attribute.id] ?? ''}
                                    check={checks.box(`variant-value-${attribute.id}`)}
                                    // Read as it is when chosen: a value made from here arrives after the form last changed.
                                    onChange={(valueId) => form.setData((data) => ({ ...data, values: { ...data.values, [attribute.id]: valueId } }))}
                                />
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

export function DeleteVariantDialog({ productId, variant, open, onOpenChange, returnFocusTo }: { productId: string; variant: VariantData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
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

/** A variant's photos, up to 10, dragged into their order. */
export function VariantPhotosDialog({
    page,
    variant,
    reason,
    onOpenChange,
    returnFocusTo,
}: {
    page: ProductPage;
    variant: VariantData;
    reason: string | undefined;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();

    return (
        <PanelDialog
            wide
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_products.variants.photos_title', { code: variant.code })}
            description={t('catalog::admin_products.variants.photos_body')}
            busy={false}
            confirm={null}
        >
            <PhotoGrid
                photos={variant.photos}
                url={`/admin/products/${page.product.id}/variants/${variant.id}/photos`}
                max={10}
                helper={t('catalog::admin_products.photos.variant_helper')}
                reason={reason}
                testPrefix="variant-photos"
            />
        </PanelDialog>
    );
}
