import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button, Checkbox, EmptyState, Input, Note, Select, Textarea } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type {
    AddressFormatField,
    AddressFormatPage,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| The store address format editor (frontend.md §3.7, decided 2026-09-19).
|
| **A country's address form is data.** A store that asks for a district today and a postal code
| tomorrow is a row changed here, not a release - which is why this screen exists at all rather
| than the formats being seeded once and left (access.md §1.9).
|
| The order of the fields is the order of this list. Nobody types a number: they move a field up or
| down, and what that means as an integer is the server's business.
|
| There is no preview of the printed address. Whether a line disappears when its field is empty is
| the domain's rule, in one place, and a second copy of it in this file would be a copy that drifts
| - so the template is explained in words and checked by the server, which is the only thing that
| can answer it honestly.
|
| In Geist's fields and buttons (frontend.md 1.10). A button that cannot be used right now - moving
| the first field up, adding past the limit - stays where it is and says why in its tooltip, rather
| than greying out without a word.
*/

type Props = AddressFormatPage;

/**
 * A field as the **form** holds it.
 *
 * Under the names the endpoint takes, not the names the page arrived under: what a browser posts
 * is the domain's own vocabulary, and translating once here beats translating at every keystroke.
 */
type FieldRow = {
    key: string;
    label_ar: string;
    label_en: string;
    required: boolean;
    max_length: number;
};

function asRow(field: AddressFormatField): FieldRow {
    return {
        key: field.key,
        label_ar: field.labelAr,
        label_en: field.labelEn,
        required: field.required,
        max_length: field.maxLength,
    };
}

export default function Index({
    stores,
    storeId,
    exists,
    fields,
    displayTemplate,
    maxFields,
    maxLength,
}: Props) {
    const t = useTranslator();

    // Keyed on the store, so choosing another country starts that country's form rather than
    // leaving the last one's rows on the screen under a new heading.
    return (
        <AdminLayout
            title={t('access::address_formats.title')}
            subtitle={t('access::address_formats.subtitle')}
        >
            <div className="grid gap-4">
                <p className="text-copy-14 text-ink-muted">{t('access::address_formats.intro')}</p>

                <FormError />

                {stores.length === 0 ? (
                    <EmptyState
                        title={t('access::address_formats.no_stores_title')}
                        description={t('access::address_formats.no_stores')}
                    />
                ) : (
                    <>
                        <Select
                            id="store"
                            data-test="store"
                            label={t('access::address_formats.store')}
                            value={storeId}
                            onChange={(event) =>
                                router.get('/admin/address-formats', {
                                    store: stores.find((store) => store.id === event.target.value)?.code ?? '',
                                })
                            }
                            className="w-full max-w-xs"
                        >
                            {stores.map((store) => (
                                <option key={store.id} value={store.id}>
                                    {store.name}
                                </option>
                            ))}
                        </Select>

                        {exists ? null : <Note variant="warning">{t('access::address_formats.no_format')}</Note>}

                        <Editor
                            key={storeId}
                            storeId={storeId}
                            startingFields={fields}
                            startingTemplate={displayTemplate}
                            maxFields={maxFields}
                            maxLength={maxLength}
                        />
                    </>
                )}
            </div>
        </AdminLayout>
    );
}

function Editor({
    storeId,
    startingFields,
    startingTemplate,
    maxFields,
    maxLength,
}: {
    storeId: string;
    startingFields: AddressFormatField[];
    startingTemplate: string;
    maxFields: number;
    maxLength: number;
}) {
    const t = useTranslator();
    const [rows, setRows] = useState<FieldRow[]>(() => startingFields.map(asRow));
    const form = useForm({
        fields: startingFields.map(asRow),
        display_template: startingTemplate,
    });

    function change(next: FieldRow[]) {
        setRows(next);
        form.setData('fields', next);
    }

    function edit(index: number, part: Partial<FieldRow>) {
        change(rows.map((each, at) => (at === index ? { ...each, ...part } : each)));
    }

    function move(from: number, to: number) {
        if (to < 0 || to >= rows.length) {
            return;
        }

        const row = rows[from];

        if (row === undefined) {
            return;
        }

        const next = [...rows];
        next.splice(from, 1);
        next.splice(to, 0, row);
        change(next);
    }

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/admin/address-formats/${storeId}`, { preserveScroll: true });
            }}
            className="grid gap-6"
        >
            <section className="grid gap-4">
                <div className="grid gap-1">
                    <h2 className="text-heading-16 text-ink">{t('access::address_formats.fields')}</h2>
                    <p className="text-copy-13 text-ink-muted">
                        {t('access::address_formats.fields_hint', { count: maxFields })}
                    </p>
                </div>

                {rows.length === 0 ? (
                    <EmptyState
                        title={t('access::address_formats.no_fields_title')}
                        description={t('access::address_formats.no_fields')}
                    />
                ) : (
                    <ul className="grid gap-4">
                        {rows.map((row, index) => (
                            <li key={index} data-test={`field-${index}`} className="material-base grid gap-4 p-5">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Input
                                        id={`key-${index}`}
                                        label={t('access::address_formats.field_key')}
                                        helper={t('access::address_formats.field_key_hint')}
                                        error={fieldError(form.errors, index, 'key')}
                                        dir="ltr"
                                        required
                                        value={row.key}
                                        onChange={(event) => edit(index, { key: event.target.value })}
                                    />

                                    <Input
                                        id={`length-${index}`}
                                        type="number"
                                        label={t('access::address_formats.max_length')}
                                        helper={t('access::address_formats.max_length_hint', { count: maxLength })}
                                        error={fieldError(form.errors, index, 'max_length')}
                                        min={1}
                                        max={maxLength}
                                        dir="ltr"
                                        className="tw-figure"
                                        required
                                        value={row.max_length}
                                        onChange={(event) => edit(index, { max_length: Number(event.target.value) })}
                                    />

                                    <Input
                                        id={`label-ar-${index}`}
                                        label={t('access::address_formats.label_ar')}
                                        error={fieldError(form.errors, index, 'label_ar')}
                                        lang="ar"
                                        dir="rtl"
                                        required
                                        value={row.label_ar}
                                        onChange={(event) => edit(index, { label_ar: event.target.value })}
                                    />

                                    <Input
                                        id={`label-en-${index}`}
                                        label={t('access::address_formats.label_en')}
                                        error={fieldError(form.errors, index, 'label_en')}
                                        lang="en"
                                        dir="ltr"
                                        required
                                        value={row.label_en}
                                        onChange={(event) => edit(index, { label_en: event.target.value })}
                                    />
                                </div>

                                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                                    <Checkbox
                                        id={`required-${index}`}
                                        checked={row.required}
                                        onChange={(checked) => edit(index, { required: checked })}
                                    >
                                        {t('access::address_formats.required')}
                                    </Checkbox>

                                    <div className="flex gap-1">
                                        <Button
                                            type="tertiary"
                                            size="small"
                                            disabledReason={index === 0 ? t('access::address_formats.first_already') : undefined}
                                            data-test={`up-${index}`}
                                            onClick={() => move(index, index - 1)}
                                        >
                                            {t('access::address_formats.move_up')}
                                        </Button>

                                        <Button
                                            type="tertiary"
                                            size="small"
                                            disabledReason={
                                                index === rows.length - 1 ? t('access::address_formats.last_already') : undefined
                                            }
                                            data-test={`down-${index}`}
                                            onClick={() => move(index, index + 1)}
                                        >
                                            {t('access::address_formats.move_down')}
                                        </Button>

                                        <Button
                                            type="tertiary"
                                            size="small"
                                            data-test={`remove-${index}`}
                                            onClick={() => change(rows.filter((_, at) => at !== index))}
                                        >
                                            {t('access::address_formats.remove_field')}
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <Button
                    type="secondary"
                    size="small"
                    className="w-fit"
                    data-test="add-field"
                    disabledReason={
                        rows.length >= maxFields ? t('access::address_formats.too_many_fields', { count: maxFields }) : undefined
                    }
                    onClick={() =>
                        change([
                            ...rows,
                            { key: '', label_ar: '', label_en: '', required: false, max_length: 100 },
                        ])
                    }
                >
                    {t('access::address_formats.add_field')}
                </Button>
            </section>

            <section className="material-base grid gap-2 p-5">
                <Textarea
                    id="display_template"
                    label={<span className="text-heading-16">{t('access::address_formats.template')}</span>}
                    helper={t('access::address_formats.template_hint')}
                    error={form.errors.display_template}
                    dir="ltr"
                    rows={6}
                    value={form.data.display_template}
                    onChange={(event) => form.setData('display_template', event.target.value)}
                    className="[&_textarea]:font-mono"
                />

                <p className="text-copy-13 text-ink-muted" dir="ltr">
                    {t('access::address_formats.template_fields', {
                        keys: rows.map((row) => `{${row.key}}`).join(' '),
                    })}
                </p>
            </section>

            <div className="grid gap-3">
                <p className="text-copy-13 text-ink-muted">{t('access::address_formats.existing_addresses')}</p>

                <Button typeName="submit" loading={form.processing} data-test="save" className="w-fit">
                    {t('access::address_formats.save')}
                </Button>
            </div>
        </form>
    );
}

/**
 * The message for one part of one field.
 *
 * Laravel names these `fields.2.key`, which is not a key of the form's own typed errors, so it is
 * looked up rather than read off a property.
 */
function fieldError(errors: Record<string, string>, index: number, part: string): string | undefined {
    return errors[`fields.${index}.${part}`];
}
