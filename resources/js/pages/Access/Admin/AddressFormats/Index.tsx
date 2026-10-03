import { useState, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useTranslator } from '@/lib/t';
import type { AddressFormatField, AddressFormatPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

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
| shadcn's parts with Geist's rules (frontend.md §1.11): each field is a FieldSet named "Field 2",
| so its four inputs are heard as that field's; "Required" is one on/off choice, a Switch (Geist's
| Toggle); a row holds one control of its own - its ⋯ menu, with Move Up and Move Down and, last
| after a divider, Remove Field (Geist's Entity: more than two controls go in a Dots Menu). An
| action that cannot be done right now - moving the first field up, adding past the limit - stays
| where it is and says why, rather than greying out without a word. The printed form's keys are
| shown as inline code (Geist's Snippet rules).
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

export default function Index({ stores, storeId, exists, fields, displayTemplate, maxFields, maxLength }: Props) {
    const t = useTranslator();

    // Keyed on the store, so choosing another country starts that country's form rather than
    // leaving the last one's rows on the screen under a new heading.
    return (
        <AdminLayout title={t('access::address_formats.title')} subtitle={t('access::address_formats.subtitle')}>
            <div className="grid gap-4">
                <p className="text-copy-14 text-ink-muted">{t('access::address_formats.intro')}</p>

                <FormError />

                {stores.length === 0 ? (
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle>{t('access::address_formats.no_stores_title')}</EmptyTitle>
                            <EmptyDescription>{t('access::address_formats.no_stores')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <SelectField
                            id="store"
                            data-test="store"
                            label={t('access::address_formats.store')}
                            className="max-w-xs"
                            value={storeId}
                            onChange={(event) =>
                                router.get('/admin/address-formats', {
                                    store: stores.find((store) => store.id === event.target.value)?.code ?? '',
                                })
                            }
                        >
                            {/* An off store is listed only to a Super Admin preparing it, marked Off
                                (access.md amendment 58(a)); an option holds text only. */}
                            {stores.map((store) => (
                                <NativeSelectOption key={store.id} value={store.id}>
                                    {store.isActive ? store.name : `${store.name} · ${t('admin.store.off')}`}
                                </NativeSelectOption>
                            ))}
                        </SelectField>

                        {exists ? null : <Note variant="warning">{t('access::address_formats.no_format')}</Note>}

                        <Editor key={storeId} storeId={storeId} startingFields={fields} startingTemplate={displayTemplate} maxFields={maxFields} maxLength={maxLength} />
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
            <section className="grid gap-4" aria-labelledby="fields-title">
                <div className="grid gap-1">
                    <h2 id="fields-title" className="text-heading-16 text-ink">
                        {t('access::address_formats.fields')}
                    </h2>
                    <p className="text-copy-13 text-ink-muted">{t('access::address_formats.fields_hint', { count: maxFields })}</p>
                </div>

                {rows.length === 0 ? (
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle>{t('access::address_formats.no_fields_title')}</EmptyTitle>
                            <EmptyDescription>{t('access::address_formats.no_fields')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div role="list" className="grid gap-4">
                        {rows.map((row, index) => (
                            <div key={index} role="listitem" data-test={`field-${index}`}>
                                <FieldSet className="material-base gap-4 p-5">
                                    <div className="flex items-center justify-between gap-3">
                                        <FieldLegend className="mb-0 text-heading-14 text-ink">
                                            <span className="tw-figure">{t('access::address_formats.field_number', { number: index + 1 })}</span>
                                        </FieldLegend>
                                        <RowMenu
                                            index={index}
                                            last={index === rows.length - 1}
                                            onMove={(to) => move(index, to)}
                                            onRemove={() => change(rows.filter((_, at) => at !== index))}
                                        />
                                    </div>

                                    <FieldGroup className="grid gap-4 sm:grid-cols-2">
                                        <TextField
                                            id={`key-${index}`}
                                            label={t('access::address_formats.field_key')}
                                            helper={t('access::address_formats.field_key_hint')}
                                            error={fieldError(form.errors, index, 'key')}
                                            dir="ltr"
                                            required
                                            value={row.key}
                                            onChange={(event) => edit(index, { key: event.target.value })}
                                        />
                                        <TextField
                                            id={`length-${index}`}
                                            type="number"
                                            label={t('access::address_formats.max_length')}
                                            helper={t('access::address_formats.max_length_hint', { count: maxLength })}
                                            error={fieldError(form.errors, index, 'max_length')}
                                            min={1}
                                            max={maxLength}
                                            dir="ltr"
                                            inputClassName="tw-figure"
                                            required
                                            value={row.max_length}
                                            onChange={(event) => edit(index, { max_length: Number(event.target.value) })}
                                        />
                                        <TextField
                                            id={`label-ar-${index}`}
                                            label={t('access::address_formats.label_ar')}
                                            error={fieldError(form.errors, index, 'label_ar')}
                                            lang="ar"
                                            dir="rtl"
                                            required
                                            value={row.label_ar}
                                            onChange={(event) => edit(index, { label_ar: event.target.value })}
                                        />
                                        <TextField
                                            id={`label-en-${index}`}
                                            label={t('access::address_formats.label_en')}
                                            error={fieldError(form.errors, index, 'label_en')}
                                            lang="en"
                                            dir="ltr"
                                            required
                                            value={row.label_en}
                                            onChange={(event) => edit(index, { label_en: event.target.value })}
                                        />
                                    </FieldGroup>

                                    <Field orientation="horizontal" className="w-fit border-t border-line pt-4">
                                        <Switch
                                            id={`required-${index}`}
                                            checked={row.required}
                                            onCheckedChange={(on) => edit(index, { required: on })}
                                            className="data-[state=unchecked]:bg-ink-subtle"
                                        />
                                        <FieldLabel htmlFor={`required-${index}`} className="text-label-14 font-normal text-ink">
                                            {t('access::address_formats.required')}
                                        </FieldLabel>
                                    </Field>
                                </FieldSet>
                            </div>
                        ))}
                    </div>
                )}

                <ActionButton
                    type="button"
                    variant="outline"
                    className="w-fit"
                    data-test="add-field"
                    disabledReason={rows.length >= maxFields ? t('access::address_formats.too_many_fields', { count: maxFields }) : undefined}
                    onClick={() => change([...rows, { key: '', label_ar: '', label_en: '', required: false, max_length: 100 }])}
                >
                    {t('access::address_formats.add_field')}
                </ActionButton>
            </section>

            <FieldSet className="material-base gap-3 p-5">
                <FieldLegend id="template-title" className="mb-0 text-heading-16 text-ink">
                    {t('access::address_formats.template')}
                </FieldLegend>
                <Field>
                    {/* The legend is the field's name: one heading, not a label dressed as one. */}
                    <Textarea
                        id="display_template"
                        aria-labelledby="template-title"
                        aria-describedby="display_template-helper"
                        aria-invalid={form.errors.display_template ? true : undefined}
                        dir="ltr"
                        rows={6}
                        className="font-mono"
                        value={form.data.display_template}
                        onChange={(event) => form.setData('display_template', event.target.value)}
                    />
                    <FieldDescription id="display_template-helper">{t('access::address_formats.template_hint')}</FieldDescription>
                    {form.errors.display_template ? <FieldError>{form.errors.display_template}</FieldError> : null}
                </Field>

                <p className="text-copy-13 text-ink-muted" dir="ltr">
                    <Keys text={t('access::address_formats.template_fields', { keys: MARK })} keys={rows.map((row) => row.key).filter((key) => key !== '')} />
                </p>
            </FieldSet>

            <div className="grid gap-3">
                <p className="text-copy-13 text-ink-muted">{t('access::address_formats.existing_addresses')}</p>

                <ActionButton type="submit" loading={form.processing} data-test="save" className="w-fit">
                    {t('access::address_formats.save')}
                </ActionButton>
            </div>
        </form>
    );
}

/**
 * One field's ⋯ menu: Move Up, Move Down, and Remove Field last after a divider. A move that
 * cannot happen stays in the menu and says why under its name, as a disabled action must (Geist).
 */
function RowMenu({ index, last, onMove, onRemove }: { index: number; last: boolean; onMove: (to: number) => void; onRemove: () => void }) {
    const t = useTranslator();
    const label = t('access::address_formats.field_number', { number: index + 1 });

    const move = (to: number, reason: string | null, text: string, test: string): ReactNode => (
        <DropdownMenuItem
            aria-disabled={reason === null ? undefined : 'true'}
            className={reason === null ? undefined : 'flex-col items-start gap-0.5 opacity-60'}
            onSelect={(event) => {
                if (reason !== null) {
                    event.preventDefault();

                    return;
                }
                onMove(to);
            }}
            data-test={test}
        >
            {text}
            {reason === null ? null : <span className="text-copy-13 text-ink-muted">{reason}</span>}
        </DropdownMenuItem>
    );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="ghost" size="icon-sm" aria-label={`${t('admin.more_actions')}: ${label}`} title={t('admin.more_actions')} data-test={`menu-${index}`}>
                    <MoreHorizontal aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                {move(index - 1, index === 0 ? t('access::address_formats.first_already') : null, t('access::address_formats.move_up'), `up-${index}`)}
                {move(index + 1, last ? t('access::address_formats.last_already') : null, t('access::address_formats.move_down'), `down-${index}`)}
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={onRemove} data-test={`remove-${index}`}>
                    {t('access::address_formats.remove_field')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Stands in for the list of keys inside a translated sentence, so each language keeps its order. */
const MARK = '⁣';

/** "Fields you can use: {city} {district}", each key as inline code (shadcn's typography-inline-code). */
function Keys({ text, keys }: { text: string; keys: string[] }) {
    const [before, after] = text.split(MARK);

    return (
        <>
            {before}
            {keys.map((key, at) => (
                <span key={`${key}-${at}`}>
                    {at === 0 ? null : ' '}
                    <code className="relative rounded bg-muted px-[0.3rem] py-[0.2rem] font-mono text-label-13 font-semibold">{`{${key}}`}</code>
                </span>
            ))}
            {after}
        </>
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
