import { type RefObject, useEffect, useRef, useState } from 'react';
import { type InertiaFormProps, router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { type Checks, useChecks } from '@/lib/use-checks';
import type { AttributeData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, nameIn, useLocale } from '../parts';

/*
| An attribute's form (catalog.md §1.7, §4.4 S3): both names, its job - details only, a filter, or
| making variants - its unit in both languages or neither, whether it is a colour (a filter or
| variant-making attribute only), and its place. Its job and colour are locked, with the reason, once
| it has values or variants carry details of it (amendments 1(i), 3(k)); its job kept "Makes
| Variants" while products make their variants of it (amendment 16(b)). Added in a dialog from the list; edited on the attribute's
| own page, where its details are this form (S3).
*/

export const KINDS = ['INFORMATIONAL', 'FILTERABLE', 'VARIANT'] as const;

type AttributeForm = { name_ar: string; name_en: string; kind: string; unit_ar: string; unit_en: string; is_colour: boolean; position: string };

/** The form of a new attribute, or of this one as it is. */
export function attributeForm(attribute: AttributeData | null, nextPosition: number): AttributeForm {
    return {
        name_ar: attribute?.nameAr ?? '',
        name_en: attribute?.nameEn ?? '',
        kind: attribute?.kind ?? 'FILTERABLE',
        unit_ar: attribute?.unitAr ?? '',
        unit_en: attribute?.unitEn ?? '',
        is_colour: attribute?.isColour ?? false,
        position: String(attribute?.position ?? nextPosition),
    };
}

/**
 * The attribute's boxes as typed (frontend.md §1.7), for the dialog and its own page alike, with the
 * domain's rules: names of up to 100 characters (Attribute::NAME_MAX, LocalizedName), units of up to
 * 20 (Attribute::UNIT_MAX), a position from 0 to 10,000 (ListPosition; the request reads an empty or
 * broken number as -1, so it is required). A unit's "both languages or neither" is left to the server.
 * `off` - every field out of reach - checks nothing.
 */
export function useAttributeChecks(form: InertiaFormProps<AttributeForm>, off = false): Checks {
    const t = useTranslator();
    const name = { required: true, length: { max: 100 } };
    const unit = { length: { max: 20 } };

    return useChecks([
        { id: 'attribute-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name, off },
        { id: 'attribute-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name, off },
        { id: 'attribute-unit-ar', label: t('catalog::admin_attributes.field.unit_ar'), value: form.data.unit_ar, rules: unit, off },
        { id: 'attribute-unit-en', label: t('catalog::admin_attributes.field.unit_en'), value: form.data.unit_en, rules: unit, off },
        { id: 'attribute-position', label: t('catalog::admin.field.position'), value: form.data.position, rules: { required: true, number: { min: 0, max: 10000 } }, off },
    ]);
}

/** Add an attribute, from the list. */
export function AttributeDialog({
    nextPosition,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    nextPosition: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<AttributeForm>(attributeForm(null, nextPosition));
    const checks = useAttributeChecks(form);

    useEffect(() => {
        if (open) {
            form.setDefaults(attributeForm(null, nextPosition));
            form.reset();
            form.clearErrors();
        }
    }, [open]);

    function submit() {
        checks.submit(() => form.post('/admin/attributes', { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_attributes.add')}
            description={t('catalog::admin_attributes.add_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-attribute">
                    {t('catalog::admin_attributes.add')}
                </ActionButton>
            }
        >
            <AttributeFields form={form} checks={checks} attribute={null} />
        </PanelDialog>
    );
}

/**
 * The attribute's fields, in a dialog or on its own page; `off` keeps every field out of reach.
 * `checks` is the form's useAttributeChecks, made where the form is sent.
 */
export function AttributeFields({ form, checks, attribute, off = false }: { form: InertiaFormProps<AttributeForm>; checks: Checks; attribute: AttributeData | null; off?: boolean }) {
    const t = useTranslator();
    // Values, or variants carrying details of it, lock both its job and Colour; products making their
    // variants of it keep only its job (S3, amendment 1(i)) - Colour may still change until it has values.
    const lockedReason = attribute?.kindLocked === true ? t('catalog::admin_attributes.reason.locked') : null;
    const kindReason = lockedReason ?? (attribute?.inProducts === true ? t('catalog::admin_attributes.reason.in_products') : null);

    return (
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField id="attribute-name-ar" dir="rtl" disabled={off} label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} check={checks.box('attribute-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="attribute-name-ar" />
                    <TextField id="attribute-name-en" dir="ltr" disabled={off} label={t('catalog::admin.field.name_en')} value={form.data.name_en} check={checks.box('attribute-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="attribute-name-en" />
                </div>
                <SelectField
                    id="attribute-kind"
                    label={t('catalog::admin_attributes.field.kind')}
                    helper={kindReason ?? t(`catalog::admin_attributes.kind_helper.${form.data.kind}`)}
                    value={form.data.kind}
                    error={form.errors.kind}
                    disabled={off || kindReason !== null}
                    onChange={(event) => form.setData((data) => ({ ...data, kind: event.target.value, is_colour: event.target.value === 'INFORMATIONAL' ? false : data.is_colour }))}
                    data-test="attribute-kind"
                >
                    {KINDS.map((kind) => (
                        <NativeSelectOption key={kind} value={kind}>
                            {t(`catalog::admin_attributes.kind.${kind}`)}
                        </NativeSelectOption>
                    ))}
                </SelectField>
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField id="attribute-unit-ar" dir="rtl" disabled={off} label={t('catalog::admin_attributes.field.unit_ar')} value={form.data.unit_ar} check={checks.box('attribute-unit-ar', form.errors.unit_ar)} onChange={(event) => form.setData('unit_ar', event.target.value)} />
                    <TextField id="attribute-unit-en" dir="ltr" disabled={off} label={t('catalog::admin_attributes.field.unit_en')} helper={t('catalog::admin_attributes.field.unit_helper')} value={form.data.unit_en} check={checks.box('attribute-unit-en', form.errors.unit_en)} onChange={(event) => form.setData('unit_en', event.target.value)} />
                </div>
                {form.data.kind === 'INFORMATIONAL' ? null : (
                    <Field orientation="horizontal">
                        <FieldContent>
                            <FieldLabel htmlFor="attribute-colour" className="text-label-14 text-ink">
                                {t('catalog::admin_attributes.field.colour')}
                            </FieldLabel>
                            <FieldDescription id="attribute-colour-helper" className="text-copy-13 text-ink-muted">
                                {lockedReason ?? t('catalog::admin_attributes.field.colour_helper')}
                            </FieldDescription>
                        </FieldContent>
                        <Switch
                            id="attribute-colour"
                            checked={form.data.is_colour}
                            disabled={off || lockedReason !== null}
                            onCheckedChange={(on) => form.setData('is_colour', on)}
                            aria-describedby="attribute-colour-helper"
                            className="data-[state=unchecked]:bg-ink-subtle"
                            data-test="attribute-colour"
                        />
                    </Field>
                )}
                <TextField
                    id="attribute-position"
                    dir="ltr"
                    inputMode="numeric"
                    className="max-w-40"
                    inputClassName="tw-figure"
                    disabled={off}
                    label={t('catalog::admin.field.position')}
                    helper={t('catalog::admin.field.position_helper')}
                    value={form.data.position}
                    check={checks.box('attribute-position', form.errors.position)}
                    onChange={(event) => form.setData('position', toLatinDigits(event.target.value))}
                />
            </div>
    );
}

/** An attribute's ⋯ menu (S3): Activate or Deactivate, Delete - its details are edited on its own page. */
export function AttributeMenu({ attribute, name, open }: { attribute: AttributeData; name: string; open: (action: 'delete', trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const post = (path: string) => router.post(`/admin/attributes/${attribute.id}/${path}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const deleteReason = attribute.inUse ? t('catalog::admin_attributes.reason.in_use') : null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} busy={busy} data-test={`attribute-actions-${attribute.id}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                <DropdownMenuItem disabled={busy} onSelect={() => post(attribute.active ? 'deactivate' : 'activate')} data-test={attribute.active ? 'deactivate-attribute' : 'activate-attribute'}>
                    {attribute.active ? t('catalog::admin_attributes.deactivate') : t('catalog::admin_attributes.activate')}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    aria-disabled={deleteReason !== null || undefined}
                    className={deleteReason !== null ? 'cursor-not-allowed' : undefined}
                    onSelect={(event) => {
                        if (deleteReason !== null) {
                            event.preventDefault();

                            return;
                        }

                        open('delete', more.current);
                    }}
                    data-test="delete-attribute"
                >
                    <span className="grid gap-0.5">
                        <span className={deleteReason !== null ? 'opacity-60' : undefined}>{t('catalog::admin_attributes.delete')}</span>
                        {deleteReason !== null ? <span className="text-copy-12 text-ink-muted">{deleteReason}</span> : null}
                    </span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function DeleteAttributeDialog({ attribute, open, onOpenChange, returnFocusTo }: { attribute: AttributeData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_attributes.delete_title')}
            description={t('catalog::admin_attributes.delete_body', { name: nameIn(locale, attribute.nameAr, attribute.nameEn), count: figure(locale, attribute.values) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/attributes/${attribute.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-attribute"
                >
                    {t('catalog::admin_attributes.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
