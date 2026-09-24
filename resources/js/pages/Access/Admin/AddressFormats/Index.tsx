import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
                <p className="text-sm text-ink-muted">{t('access::address_formats.intro')}</p>

                <FormError />

                {stores.length === 0 ? (
                    <p className="rounded-lg border border-line bg-surface p-6 text-sm text-ink-muted">
                        {t('access::address_formats.no_stores')}
                    </p>
                ) : (
                    <>
                        <label className="grid w-fit gap-1 text-xs text-ink-muted">
                            {t('access::address_formats.store')}
                            <select
                                data-test="store"
                                value={storeId}
                                onChange={(event) =>
                                    router.get('/admin/address-formats', {
                                        store: stores.find((store) => store.id === event.target.value)?.code ?? '',
                                    })
                                }
                                className="h-9 rounded-md border border-line bg-surface px-3 text-sm text-ink"
                            >
                                {stores.map((store) => (
                                    <option key={store.id} value={store.id}>
                                        {store.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        {exists ? null : (
                            <p className="rounded-md border border-warn/30 bg-warn-soft px-4 py-3 text-sm text-warn">
                                {t('access::address_formats.no_format')}
                            </p>
                        )}

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
            <section className="grid gap-4 rounded-lg border border-line bg-surface p-6 shadow-card">
                <div className="grid gap-1">
                    <h2 className="text-sm font-semibold text-ink">
                        {t('access::address_formats.fields')}
                    </h2>
                    <p className="text-xs text-ink-muted">
                        {t('access::address_formats.fields_hint', { count: maxFields })}
                    </p>
                </div>

                {rows.length === 0 ? (
                    <p className="text-sm text-ink-muted">{t('access::address_formats.no_fields')}</p>
                ) : (
                    <ul className="grid gap-4">
                        {rows.map((row, index) => (
                            <li
                                key={index}
                                data-test={`field-${index}`}
                                className="grid gap-3 rounded-md border border-line p-4"
                            >
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        id={`key-${index}`}
                                        label={t('access::address_formats.field_key')}
                                        hint={t('access::address_formats.field_key_hint')}
                                        error={fieldError(form.errors, index, 'key')}
                                    >
                                        <Input
                                            id={`key-${index}`}
                                            dir="ltr"
                                            required
                                            value={row.key}
                                            onChange={(event) =>
                                                change(
                                                    rows.map((each, at) =>
                                                        at === index
                                                            ? { ...each, key: event.target.value }
                                                            : each,
                                                    ),
                                                )
                                            }
                                        />
                                    </Field>

                                    <Field
                                        id={`length-${index}`}
                                        label={t('access::address_formats.max_length')}
                                        hint={t('access::address_formats.max_length_hint', {
                                            count: maxLength,
                                        })}
                                        error={fieldError(form.errors, index, 'max_length')}
                                    >
                                        <Input
                                            id={`length-${index}`}
                                            type="number"
                                            min={1}
                                            max={maxLength}
                                            dir="ltr"
                                            className="tw-figure"
                                            required
                                            value={row.max_length}
                                            onChange={(event) =>
                                                change(
                                                    rows.map((each, at) =>
                                                        at === index
                                                            ? {
                                                                  ...each,
                                                                  max_length: Number(
                                                                      event.target.value,
                                                                  ),
                                                              }
                                                            : each,
                                                    ),
                                                )
                                            }
                                        />
                                    </Field>

                                    <Field
                                        id={`label-ar-${index}`}
                                        label={t('access::address_formats.label_ar')}
                                        error={fieldError(form.errors, index, 'label_ar')}
                                    >
                                        <Input
                                            id={`label-ar-${index}`}
                                            lang="ar"
                                            dir="rtl"
                                            required
                                            value={row.label_ar}
                                            onChange={(event) =>
                                                change(
                                                    rows.map((each, at) =>
                                                        at === index
                                                            ? { ...each, label_ar: event.target.value }
                                                            : each,
                                                    ),
                                                )
                                            }
                                        />
                                    </Field>

                                    <Field
                                        id={`label-en-${index}`}
                                        label={t('access::address_formats.label_en')}
                                        error={fieldError(form.errors, index, 'label_en')}
                                    >
                                        <Input
                                            id={`label-en-${index}`}
                                            lang="en"
                                            dir="ltr"
                                            required
                                            value={row.label_en}
                                            onChange={(event) =>
                                                change(
                                                    rows.map((each, at) =>
                                                        at === index
                                                            ? { ...each, label_en: event.target.value }
                                                            : each,
                                                    ),
                                                )
                                            }
                                        />
                                    </Field>
                                </div>

                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <label className="flex items-center gap-2 text-sm text-ink">
                                        <Checkbox
                                            checked={row.required}
                                            onCheckedChange={(checked) =>
                                                change(
                                                    rows.map((each, at) =>
                                                        at === index
                                                            ? { ...each, required: checked === true }
                                                            : each,
                                                    ),
                                                )
                                            }
                                        />
                                        {t('access::address_formats.required')}
                                    </label>

                                    <div className="flex gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={index === 0}
                                            data-test={`up-${index}`}
                                            onClick={() => move(index, index - 1)}
                                        >
                                            {t('access::address_formats.move_up')}
                                        </Button>

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={index === rows.length - 1}
                                            data-test={`down-${index}`}
                                            onClick={() => move(index, index + 1)}
                                        >
                                            {t('access::address_formats.move_down')}
                                        </Button>

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            data-test={`remove-${index}`}
                                            onClick={() =>
                                                change(rows.filter((_, at) => at !== index))
                                            }
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
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-fit"
                    data-test="add-field"
                    disabled={rows.length >= maxFields}
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

            <section className="grid gap-3 rounded-lg border border-line bg-surface p-6 shadow-card">
                <div className="grid gap-1">
                    <h2 className="text-sm font-semibold text-ink">
                        {t('access::address_formats.template')}
                    </h2>
                    <p className="text-xs text-ink-muted">
                        {t('access::address_formats.template_hint')}
                    </p>
                    <p className="text-xs text-ink-muted" dir="ltr">
                        {t('access::address_formats.template_fields', {
                            keys: rows.map((row) => `{${row.key}}`).join(' '),
                        })}
                    </p>
                </div>

                <textarea
                    id="display_template"
                    dir="ltr"
                    rows={6}
                    value={form.data.display_template}
                    onChange={(event) => form.setData('display_template', event.target.value)}
                    className="w-full rounded-md border border-line bg-surface p-3 font-mono text-sm text-ink"
                />

                {form.errors.display_template ? (
                    <p role="alert" className="text-xs text-bad">
                        {form.errors.display_template}
                    </p>
                ) : null}
            </section>

            <p className="text-xs text-ink-muted">
                {t('access::address_formats.existing_addresses')}
            </p>

            <Button type="submit" disabled={form.processing} data-test="save" className="w-fit">
                {t('access::address_formats.save')}
            </Button>
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
