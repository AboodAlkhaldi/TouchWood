import { type RefObject, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { StaffTypeRowData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { figure, nameIn, useLocale } from '../shared';
import { PanelDialog } from '@/components/PanelDialog';

/*
| The types page's dialogs (b2b.md §1.3, §4.6): adding, renaming and moving a type; deactivating one,
| with what happens to the companies holding a company type; and moving every company of one active
| type to another. Each in the staff dialog frame (PanelDialog): a title stating what happens, the
| button repeating it, a refusal said inside it.
|
| A type's names and position are checked by the domain, and a refusal comes back beside the field
| it names. Positions accept Arabic-Indic digits, as every number input does (frontend.md §1.8).
| Deactivating is destructive (shadcn's AlertDialog); the rest are plain Dialogs.
*/

export type Kind = 'company' | 'document';

function base(kind: Kind): string {
    return kind === 'company' ? '/admin/company-types' : '/admin/document-types';
}

type TypeForm = { name_ar: string; name_en: string; position: string; required: boolean };

/** Add a type, rename one, or move one: the same fields, as many as the job needs. */
export function TypeFormModal({
    mode,
    kind,
    type,
    open,
    onOpenChange,
    nextPosition,
    store,
    returnFocusTo,
}: {
    mode: 'add' | 'rename' | 'move';
    kind: Kind;
    type: StaffTypeRowData | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    nextPosition: number;
    /** The store the page shows: a new type is added to it (b2b.md amendment 30). */
    store: string;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<TypeForm>({
        name_ar: type?.nameAr ?? '',
        name_en: type?.nameEn ?? '',
        position: String(type?.position ?? nextPosition),
        required: true,
    });

    // Every opening starts from the type as it is now, never from what was typed for another.
    useEffect(() => {
        if (open) {
            form.setDefaults({ name_ar: type?.nameAr ?? '', name_en: type?.nameEn ?? '', position: String(type?.position ?? nextPosition), required: true });
            form.reset();
            form.clearErrors();
        }
        // Keyed on the opening and the type only: the form itself changes on every keystroke.
    }, [open, type?.id]);

    const title =
        mode === 'add'
            ? t(`b2b::admin_types.list.${kind}.add`)
            : mode === 'rename'
              ? t(`b2b::admin_types.list.${kind}.rename_title`)
              : t(`b2b::admin_types.list.${kind}.move_title`);
    const body =
        mode === 'add' ? t(`b2b::admin_types.list.${kind}.add_body`) : mode === 'rename' ? t(`b2b::admin_types.list.${kind}.rename_body`) : t(`b2b::admin_types.list.${kind}.move_body`);

    // Each box as typed (frontend.md §1.7), with the domain's rules: names required, one line of at
    // most 100 characters (TypeName::MAX), not sent by a move; a position from 0 to 10,000
    // (TypePosition; the request reads an empty or broken number as -1, so it is required), not sent
    // by a rename.
    const name = { required: true, length: { max: 100 } };
    const checks = useChecks([
        { id: 'type-name-ar', label: t('b2b::admin_types.field.name_ar'), value: form.data.name_ar, rules: name, off: mode === 'move' },
        { id: 'type-name-en', label: t('b2b::admin_types.field.name_en'), value: form.data.name_en, rules: name, off: mode === 'move' },
        { id: 'type-position', label: t('b2b::admin_types.field.position'), value: form.data.position, rules: { required: true, number: { min: 0, max: 10000 } }, off: mode === 'rename' },
    ]);

    function submit() {
        const target = mode === 'add' ? base(kind) : `${base(kind)}/${type?.id ?? ''}/${mode}`;
        // A new type names its store; a type changed is found in its own.
        form.transform((data) => (mode === 'add' ? { ...data, store } : data));
        checks.submit(() => form.post(target, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            open={open}
            returnFocusTo={returnFocusTo}
            onOpenChange={onOpenChange}
            title={title}
            description={body}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test={`confirm-${mode}`}>
                    {title}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {mode === 'move' ? null : (
                    <>
                        <TextField
                            id="type-name-ar"
                            dir="rtl"
                            label={t('b2b::admin_types.field.name_ar')}
                            value={form.data.name_ar}
                            check={checks.box('type-name-ar', form.errors.name_ar)}
                            onChange={(event) => form.setData('name_ar', event.target.value)}
                            data-test="type-name-ar"
                        />
                        <TextField
                            id="type-name-en"
                            dir="ltr"
                            label={t('b2b::admin_types.field.name_en')}
                            value={form.data.name_en}
                            check={checks.box('type-name-en', form.errors.name_en)}
                            onChange={(event) => form.setData('name_en', event.target.value)}
                            data-test="type-name-en"
                        />
                    </>
                )}
                {mode === 'rename' ? null : (
                    <TextField
                        id="type-position"
                        dir="ltr"
                        inputMode="numeric"
                        className="max-w-40"
                        inputClassName="tw-figure"
                        label={t('b2b::admin_types.field.position')}
                        helper={t('b2b::admin_types.field.position_helper')}
                        value={form.data.position}
                        check={checks.box('type-position', form.errors.position)}
                        onChange={(event) => form.setData('position', toLatinDigits(event.target.value))}
                        data-test="type-position"
                    />
                )}
                {/* One setting on its own is a switch, its sentence tied to it (Geist's Toggle,
                    shadcn's field-switch). */}
                {mode === 'add' && kind === 'document' ? (
                    <Field orientation="horizontal">
                        <FieldContent>
                            <FieldLabel htmlFor="type-required" className="text-label-14 text-ink">
                                {t('b2b::admin_types.field.required')}
                            </FieldLabel>
                            <FieldDescription id="type-required-helper" className="text-copy-13 text-ink-muted">
                                {t('b2b::admin_types.field.required_helper')}
                            </FieldDescription>
                        </FieldContent>
                        <Switch
                            id="type-required"
                            checked={form.data.required}
                            onCheckedChange={(on) => form.setData('required', on)}
                            aria-describedby="type-required-helper"
                            className="data-[state=unchecked]:bg-ink-subtle"
                            data-test="type-required"
                        />
                    </Field>
                ) : null}
            </div>
        </PanelDialog>
    );
}

type DeactivateForm = {
    shown: string;
    holders: string;
    replacement: string;
    new_name_ar: string;
    new_name_en: string;
    new_position: string;
};

/** A radio group as shadcn's field-radio writes it: the legend, its sentence inside the set, and each choice a labelled radio. */
function Choices({
    name,
    legend,
    helper,
    value,
    onChange,
    options,
    error,
}: {
    name: string;
    legend: string;
    helper?: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    error?: string;
}) {
    const described = [helper === undefined ? null : `${name}-helper`, error ? `${name}-error` : null].filter(Boolean).join(' ');

    return (
        <FieldSet className="gap-3" data-invalid={error ? true : undefined}>
            <FieldLegend variant="label" className="text-label-14 text-ink">
                {legend}
            </FieldLegend>
            {helper === undefined ? null : (
                <FieldDescription id={`${name}-helper`} className="text-copy-13 text-ink-muted">
                    {helper}
                </FieldDescription>
            )}
            <RadioGroup name={name} value={value} onValueChange={onChange} aria-describedby={described === '' ? undefined : described} className="gap-2">
                {options.map((option) => (
                    <Field key={option.value} orientation="horizontal" className="gap-2">
                        <RadioGroupItem id={`${name}-${option.value}`} value={option.value} aria-invalid={error ? true : undefined} />
                        <FieldLabel htmlFor={`${name}-${option.value}`} className="text-label-14 font-normal text-ink">
                            {option.label}
                        </FieldLabel>
                    </Field>
                ))}
            </RadioGroup>
            {error ? <FieldError id={`${name}-error`}>{error}</FieldError> : null}
        </FieldSet>
    );
}

/**
 * How it shows to new applications (amendment 5); for a company type that companies hold, what
 * happens to them — left, moved to another active type, or moved to a new type made in the same step,
 * which needs the job of adding types too (amendment 11(b)). Suspended companies keep it (10(h)).
 */
export function DeactivateModal({
    kind,
    type,
    others,
    mayIntoNew,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    kind: Kind;
    type: StaffTypeRowData;
    others: StaffTypeRowData[];
    mayIntoNew: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm<DeactivateForm>({ shown: 'HIDDEN', holders: 'leave', replacement: '', new_name_ar: '', new_name_en: '', new_position: '' });
    const holders = kind === 'company' ? (type.holders ?? 0) : 0;
    const title = t(`b2b::admin_types.list.${kind}.deactivate_title`);
    // Each box as typed (frontend.md §1.7), checked only while its choice is made - the only time the
    // controller reads it: the replacement chosen (CompanyTypeHolders::target refuses none); the new
    // type's names required, one line of at most 100 characters (TypeName::MAX), and its position
    // from 0 to 10,000 (TypePosition) - optional, as empty keeps this type's position (amendment 11(b)).
    const into = holders > 0 ? form.data.holders : 'leave';
    const name = { required: true, length: { max: 100 } };
    const checks = useChecks([
        { id: 'deactivate-replacement', label: t('b2b::admin_types.holders.replacement'), value: form.data.replacement, rules: { required: true }, off: into !== 'replace' },
        { id: 'deactivate-new-name-ar', label: t('b2b::admin_types.field.name_ar'), value: form.data.new_name_ar, rules: name, off: into !== 'new' },
        { id: 'deactivate-new-name-en', label: t('b2b::admin_types.field.name_en'), value: form.data.new_name_en, rules: name, off: into !== 'new' },
        { id: 'deactivate-new-position', label: t('b2b::admin_types.field.position'), value: form.data.new_position, rules: { number: { min: 0, max: 10000 } }, off: into !== 'new' },
    ]);

    function submit() {
        checks.submit(() => form.post(`${base(kind)}/${type.id}/deactivate`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            open={open}
            returnFocusTo={returnFocusTo}
            onOpenChange={onOpenChange}
            destructive
            title={title}
            description={t(`b2b::admin_types.list.${kind}.deactivate_body`, { name: nameIn(locale, type.nameAr, type.nameEn) })}
            busy={form.processing}
            confirm={
                <ActionButton variant="destructive" loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-deactivate">
                    {title}
                </ActionButton>
            }
        >
            <div className="grid gap-5">
                <Choices
                    name="shown"
                    legend={t('b2b::admin_types.shown.legend')}
                    helper={t('b2b::admin_types.shown.helper')}
                    value={form.data.shown}
                    onChange={(value) => form.setData('shown', value)}
                    error={form.errors.shown}
                    options={[
                        { value: 'HIDDEN', label: t('b2b::admin_types.shown.HIDDEN') },
                        { value: 'GREYED', label: t('b2b::admin_types.shown.GREYED') },
                    ]}
                />

                {holders > 0 ? (
                    <div className="grid gap-3" data-test="holders-choice">
                        <Choices
                            name="holders"
                            legend={t('b2b::admin_types.holders.legend')}
                            helper={t('b2b::admin_types.holders.count', { count: figure(locale, holders) })}
                            value={form.data.holders}
                            onChange={(value) => form.setData('holders', value)}
                            options={[
                                { value: 'leave', label: t('b2b::admin_types.holders.leave') },
                                { value: 'replace', label: t('b2b::admin_types.holders.replace') },
                                // Offered only to someone who may add types as well (§4.6, scenario 49).
                                ...(mayIntoNew ? [{ value: 'new', label: t('b2b::admin_types.holders.new') }] : []),
                            ]}
                        />

                        {form.data.holders === 'replace' ? (
                            <SelectField
                                id="deactivate-replacement"
                                label={t('b2b::admin_types.holders.replacement')}
                                value={form.data.replacement}
                                check={checks.box('deactivate-replacement', form.errors.replacement)}
                                onChange={(event) => form.setData('replacement', event.target.value)}
                                data-test="deactivate-replacement"
                            >
                                <NativeSelectOption value="" disabled>
                                    {t('b2b::admin_types.list.company.choose')}
                                </NativeSelectOption>
                                {others.map((other) => (
                                    <NativeSelectOption key={other.id} value={other.id}>
                                        {nameIn(locale, other.nameAr, other.nameEn)}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>
                        ) : null}

                        {form.data.holders === 'new' ? (
                            <div className="grid gap-3">
                                <TextField
                                    id="deactivate-new-name-ar"
                                    dir="rtl"
                                    label={t('b2b::admin_types.field.name_ar')}
                                    value={form.data.new_name_ar}
                                    check={checks.box('deactivate-new-name-ar', form.errors.new_name_ar)}
                                    onChange={(event) => form.setData('new_name_ar', event.target.value)}
                                    data-test="deactivate-new-name-ar"
                                />
                                <TextField
                                    id="deactivate-new-name-en"
                                    dir="ltr"
                                    label={t('b2b::admin_types.field.name_en')}
                                    value={form.data.new_name_en}
                                    check={checks.box('deactivate-new-name-en', form.errors.new_name_en)}
                                    onChange={(event) => form.setData('new_name_en', event.target.value)}
                                    data-test="deactivate-new-name-en"
                                />
                                <TextField
                                    id="deactivate-new-position"
                                    dir="ltr"
                                    inputMode="numeric"
                                    className="max-w-40"
                                    inputClassName="tw-figure"
                                    label={t('b2b::admin_types.field.position')}
                                    helper={t('b2b::admin_types.holders.new_position_helper')}
                                    value={form.data.new_position}
                                    check={checks.box('deactivate-new-position', form.errors.new_position)}
                                    onChange={(event) => form.setData('new_position', toLatinDigits(event.target.value))}
                                />
                            </div>
                        ) : null}

                        <Note variant="secondary" size="small">
                            {t('b2b::admin_types.holders.suspended')}
                        </Note>
                    </div>
                ) : null}
            </div>
        </PanelDialog>
    );
}

/** Every company of one active type to another, both staying offered (amendment 11(c)). */
export function TransferModal({
    type,
    others,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    type: StaffTypeRowData;
    others: StaffTypeRowData[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm({ target: '' });
    const title = t('b2b::admin_types.list.company.transfer_title');
    // The type they go to, chosen (frontend.md §1.7): it opens with none, which
    // CompanyTypeHolders::target refuses.
    const checks = useChecks([{ id: 'transfer-target', label: t('b2b::admin_types.list.company.transfer_target'), value: form.data.target, rules: { required: true } }]);

    return (
        <PanelDialog
            open={open}
            returnFocusTo={returnFocusTo}
            onOpenChange={onOpenChange}
            title={title}
            description={t('b2b::admin_types.list.company.transfer_body', { name: nameIn(locale, type.nameAr, type.nameEn) })}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={checks.reason}
                    onClick={() => checks.submit(() => form.post(`/admin/company-types/${type.id}/transfer`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }))}
                    data-test="confirm-transfer"
                >
                    {title}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <SelectField
                    id="transfer-target"
                    label={t('b2b::admin_types.list.company.transfer_target')}
                    value={form.data.target}
                    check={checks.box('transfer-target', form.errors.target)}
                    onChange={(event) => form.setData('target', event.target.value)}
                    data-test="transfer-target"
                >
                    <NativeSelectOption value="" disabled>
                        {t('b2b::admin_types.list.company.choose')}
                    </NativeSelectOption>
                    {others.map((other) => (
                        <NativeSelectOption key={other.id} value={other.id}>
                            {nameIn(locale, other.nameAr, other.nameEn)}
                        </NativeSelectOption>
                    ))}
                </SelectField>
                <Note variant="secondary" size="small">
                    {t('b2b::admin_types.holders.suspended')}
                </Note>
            </div>
        </PanelDialog>
    );
}
