import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Checkbox, Input, Modal, ModalCancel, Note, RadioGroup, Select } from '@/components/geist';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { StaffTypeRowData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { figure, nameIn, useLocale } from '../shared';

/*
| The types page's dialogs (b2b.md §1.3, §4.6): adding, renaming and moving a type; deactivating one,
| with what happens to the companies holding a company type; and moving every company of one active
| type to another. Each in Geist's Modal: a title stating what happens, the button repeating it.
|
| A type's names and position are checked by the domain, and a refusal comes back beside the field
| it names. Positions accept Arabic-Indic digits, as every number input does (frontend.md §1.8).
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
}: {
    mode: 'add' | 'rename' | 'move';
    kind: Kind;
    type: StaffTypeRowData | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    nextPosition: number;
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

    function submit() {
        const target = mode === 'add' ? base(kind) : `${base(kind)}/${type?.id ?? ''}/${mode}`;
        form.post(target, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    }

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={body}
            actions={
                <>
                    <ModalCancel onClick={() => onOpenChange(false)} />
                    <Button loading={form.processing} onClick={submit} data-test={`confirm-${mode}`}>
                        {title}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                {mode === 'move' ? null : (
                    <>
                        <Input
                            id="type-name-ar"
                            dir="rtl"
                            label={t('b2b::admin_types.field.name_ar')}
                            value={form.data.name_ar}
                            error={form.errors.name_ar}
                            onChange={(event) => form.setData('name_ar', event.target.value)}
                            data-test="type-name-ar"
                        />
                        <Input
                            id="type-name-en"
                            dir="ltr"
                            label={t('b2b::admin_types.field.name_en')}
                            value={form.data.name_en}
                            error={form.errors.name_en}
                            onChange={(event) => form.setData('name_en', event.target.value)}
                            data-test="type-name-en"
                        />
                    </>
                )}
                {mode === 'rename' ? null : (
                    <Input
                        id="type-position"
                        dir="ltr"
                        inputMode="numeric"
                        className="w-40"
                        label={t('b2b::admin_types.field.position')}
                        helper={t('b2b::admin_types.field.position_helper')}
                        value={form.data.position}
                        error={form.errors.position}
                        onChange={(event) => form.setData('position', toLatinDigits(event.target.value))}
                        data-test="type-position"
                    />
                )}
                {mode === 'add' && kind === 'document' ? (
                    <div className="grid gap-1">
                        <Checkbox id="type-required" checked={form.data.required} onChange={(on) => form.setData('required', on)} data-test="type-required">
                            {t('b2b::admin_types.field.required')}
                        </Checkbox>
                        <p className="ps-6 text-copy-13 text-ink-muted">{t('b2b::admin_types.field.required_helper')}</p>
                    </div>
                ) : null}
            </div>
        </Modal>
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
}: {
    kind: Kind;
    type: StaffTypeRowData;
    others: StaffTypeRowData[];
    mayIntoNew: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm<DeactivateForm>({ shown: 'HIDDEN', holders: 'leave', replacement: '', new_name_ar: '', new_name_en: '', new_position: '' });
    const holders = kind === 'company' ? (type.holders ?? 0) : 0;
    const title = t(`b2b::admin_types.list.${kind}.deactivate_title`);

    function submit() {
        form.post(`${base(kind)}/${type.id}/deactivate`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    }

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            destructive
            title={title}
            description={t(`b2b::admin_types.list.${kind}.deactivate_body`, { name: nameIn(locale, type.nameAr, type.nameEn) })}
            actions={
                <>
                    <ModalCancel onClick={() => onOpenChange(false)} />
                    <Button type="error" loading={form.processing} onClick={submit} data-test="confirm-deactivate">
                        {title}
                    </Button>
                </>
            }
        >
            <div className="grid gap-5">
                <div className="grid gap-1">
                    <RadioGroup
                        name="shown"
                        legend={t('b2b::admin_types.shown.legend')}
                        value={form.data.shown}
                        onChange={(value) => form.setData('shown', value)}
                        error={form.errors.shown}
                        options={[
                            { value: 'HIDDEN', label: t('b2b::admin_types.shown.HIDDEN') },
                            { value: 'GREYED', label: t('b2b::admin_types.shown.GREYED') },
                        ]}
                    />
                    <p className="text-copy-13 text-ink-muted">{t('b2b::admin_types.shown.helper')}</p>
                </div>

                {holders > 0 ? (
                    <div className="grid gap-3" data-test="holders-choice">
                        <RadioGroup
                            name="holders"
                            legend={t('b2b::admin_types.holders.legend')}
                            value={form.data.holders}
                            onChange={(value) => form.setData('holders', value)}
                            options={[
                                { value: 'leave', label: t('b2b::admin_types.holders.leave') },
                                { value: 'replace', label: t('b2b::admin_types.holders.replace') },
                                {
                                    value: 'new',
                                    label: t('b2b::admin_types.holders.new'),
                                    disabledReason: mayIntoNew ? undefined : t('b2b::admin_types.holders.new_locked'),
                                },
                            ]}
                        />
                        <p className="tw-figure text-copy-13 text-ink-muted">{t('b2b::admin_types.holders.count', { count: figure(locale, holders) })}</p>

                        {form.data.holders === 'replace' ? (
                            <Select
                                id="deactivate-replacement"
                                label={t('b2b::admin_types.holders.replacement')}
                                placeholder={t('b2b::admin_types.list.company.choose')}
                                value={form.data.replacement}
                                error={form.errors.replacement}
                                onChange={(event) => form.setData('replacement', event.target.value)}
                                data-test="deactivate-replacement"
                            >
                                {others.map((other) => (
                                    <option key={other.id} value={other.id}>
                                        {nameIn(locale, other.nameAr, other.nameEn)}
                                    </option>
                                ))}
                            </Select>
                        ) : null}

                        {form.data.holders === 'new' ? (
                            <div className="grid gap-3">
                                <Input
                                    id="deactivate-new-name-ar"
                                    dir="rtl"
                                    label={t('b2b::admin_types.field.name_ar')}
                                    value={form.data.new_name_ar}
                                    error={form.errors.new_name_ar}
                                    onChange={(event) => form.setData('new_name_ar', event.target.value)}
                                    data-test="deactivate-new-name-ar"
                                />
                                <Input
                                    id="deactivate-new-name-en"
                                    dir="ltr"
                                    label={t('b2b::admin_types.field.name_en')}
                                    value={form.data.new_name_en}
                                    error={form.errors.new_name_en}
                                    onChange={(event) => form.setData('new_name_en', event.target.value)}
                                    data-test="deactivate-new-name-en"
                                />
                                <Input
                                    id="deactivate-new-position"
                                    dir="ltr"
                                    inputMode="numeric"
                                    className="w-40"
                                    label={t('b2b::admin_types.field.position')}
                                    helper={t('b2b::admin_types.holders.new_position_helper')}
                                    value={form.data.new_position}
                                    error={form.errors.new_position}
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
        </Modal>
    );
}

/** Every company of one active type to another, both staying offered (amendment 11(c)). */
export function TransferModal({
    type,
    others,
    open,
    onOpenChange,
}: {
    type: StaffTypeRowData;
    others: StaffTypeRowData[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm({ target: '' });
    const title = t('b2b::admin_types.list.company.transfer_title');

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={t('b2b::admin_types.list.company.transfer_body', { name: nameIn(locale, type.nameAr, type.nameEn) })}
            actions={
                <>
                    <ModalCancel onClick={() => onOpenChange(false)} />
                    <Button
                        loading={form.processing}
                        onClick={() => form.post(`/admin/company-types/${type.id}/transfer`, { preserveScroll: true, onSuccess: () => onOpenChange(false) })}
                        data-test="confirm-transfer"
                    >
                        {title}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                <Select
                    id="transfer-target"
                    label={t('b2b::admin_types.list.company.transfer_target')}
                    placeholder={t('b2b::admin_types.list.company.choose')}
                    value={form.data.target}
                    error={form.errors.target}
                    onChange={(event) => form.setData('target', event.target.value)}
                    data-test="transfer-target"
                >
                    {others.map((other) => (
                        <option key={other.id} value={other.id}>
                            {nameIn(locale, other.nameAr, other.nameEn)}
                        </option>
                    ))}
                </Select>
                <Note variant="secondary" size="small">
                    {t('b2b::admin_types.holders.suspended')}
                </Note>
            </div>
        </Modal>
    );
}
