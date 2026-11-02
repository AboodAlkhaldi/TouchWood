import { type RefObject, useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { Messages, TextField, checked, describedBy } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ValueData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';

/*
| Adding, changing or deleting one value of an attribute (catalog.md §1.7, §4.4 S3), on the attribute's page.
*/

type ValueForm = { name_ar: string; name_en: string; swatch: string };

/** A swatch as the domain keeps it: a hash and six hexadecimal digits (§1.7). */
const SWATCH = /^#[0-9a-f]{6}$/i;

export function ValueDialog({
    attributeId,
    colour,
    value,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    attributeId: string;
    colour: boolean;
    value: ValueData | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const initial = (): ValueForm => ({ name_ar: value?.nameAr ?? '', name_en: value?.nameEn ?? '', swatch: value?.swatch ?? '' });
    const form = useForm<ValueForm>(initial());

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
        }
    }, [open, value?.id]);

    const title = value === null ? t('catalog::admin_attributes.value.add') : t('catalog::admin_attributes.value.edit_title');
    // Each box as typed (frontend.md §1.7), with the domain's rules: names of up to 100 characters
    // (AttributeValue::NAME_MAX, LocalizedName); on a colour attribute only, a swatch, required, of the
    // shape AttributeValue::checkedSwatch takes (`#rrggbb`, 7 characters at most). A new value goes
    // last; it has no place to type (amendment 16(d)).
    const name = { required: true, length: { max: 100 } };
    const checks = useChecks([
        { id: 'value-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name },
        { id: 'value-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name },
        {
            id: 'value-swatch',
            label: t('catalog::admin_attributes.value.swatch'),
            value: form.data.swatch,
            rules: { required: true, length: { max: 7 }, format: { pattern: SWATCH, key: 'catalog::admin_attributes.check.swatch' } },
            off: !colour,
        },
    ]);
    const swatch = checks.box('value-swatch', form.errors.swatch);

    function submit() {
        form.transform((data) => (colour ? data : { ...data, swatch: '' }));
        checks.submit(() => form.post(value === null ? `/admin/attributes/${attributeId}/values` : `/admin/attribute-values/${value.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t('catalog::admin_attributes.value.body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-value">
                    {value === null ? title : t('catalog::admin_attributes.value.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField id="value-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} check={checks.box('value-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="value-name-ar" />
                <TextField id="value-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} value={form.data.name_en} check={checks.box('value-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="value-name-en" />
                {colour ? (
                    <Field>
                        <FieldLabel htmlFor="value-swatch">{t('catalog::admin_attributes.value.swatch')}</FieldLabel>
                        <div className="flex items-center gap-2">
                            {/* The browser's own picker, holding the colour typed once it is one; until
                                then it starts where the browser starts - no colour of ours is written
                                into the screen (tests/Architecture/ThemeTokensTest). */}
                            {SWATCH.test(form.data.swatch) ? (
                                <Input
                                    key="chosen"
                                    type="color"
                                    className="h-9 w-12 p-1"
                                    aria-label={t('catalog::admin_attributes.value.swatch_pick')}
                                    value={form.data.swatch}
                                    onChange={(event) => form.setData('swatch', event.target.value)}
                                />
                            ) : (
                                <Input
                                    key="unset"
                                    type="color"
                                    className="h-9 w-12 p-1"
                                    aria-label={t('catalog::admin_attributes.value.swatch_pick')}
                                    onChange={(event) => form.setData('swatch', event.target.value)}
                                />
                            )}
                            {/* Wired to its check by hand: shadcn's Input beside the picker, not a TextField. */}
                            <Input
                                id="value-swatch"
                                dir="ltr"
                                className="tw-figure max-w-32"
                                value={form.data.swatch}
                                {...checked<HTMLInputElement>(swatch, {})}
                                aria-invalid={swatch.message ? true : undefined}
                                aria-describedby={describedBy('value-swatch', t('catalog::admin_attributes.value.swatch_helper'), swatch.message)}
                                onChange={(event) => {
                                    form.setData('swatch', toLatinDigits(event.target.value));
                                    swatch.onType();
                                }}
                                data-test="value-swatch"
                            />
                        </div>
                        <Messages id="value-swatch" helper={t('catalog::admin_attributes.value.swatch_helper')} error={swatch.message} check={swatch} />
                    </Field>
                ) : null}
            </div>
        </PanelDialog>
    );
}

export function DeleteValueDialog({ value, open, onOpenChange, returnFocusTo }: { value: ValueData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_attributes.value.delete_title')}
            description={t('catalog::admin_attributes.value.delete_body', { name: nameIn(locale, value.nameAr, value.nameEn) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/attribute-values/${value.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-value"
                >
                    {t('catalog::admin_attributes.value.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
