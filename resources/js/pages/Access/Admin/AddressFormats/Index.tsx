import { useId, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { closestCenter, DndContext, KeyboardSensor, MouseSensor, TouchSensor, useSensor, useSensors, type DragEndEvent, type UniqueIdentifier } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, MoreHorizontal } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { checked, describedBy, Messages, SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { type Checks, useChecks } from '@/lib/use-checks';
import type { AddressFormatField, AddressFormatPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The store address format editor (frontend.md §3.7, decided 2026-09-19).
|
| **A country's address form is data.** A store that asks for a district today and a postal code
| tomorrow is a row changed here, not a release - which is why this screen exists at all rather
| than the formats being seeded once and left (access.md §1.9).
|
| The order of the fields is the order of this list. Nobody types a number: they drag a field by its
| handle - with the mouse, by touch, or from the keyboard - and what the order means as an integer
| is the server's business. The drag is shadcn's own pattern, its `dashboard-01` table's handles on
| dnd-kit (owner, 2026-10-03: a country has fourteen fields, and moving one a step at a time from a
| menu took thirteen steps). What a screen reader is told while a field moves is said in the page's
| language: dnd-kit's own words are English only.
|
| There is no preview of the printed address. Whether a line disappears when its field is empty is
| the domain's rule, in one place, and a second copy of it in this file would be a copy that drifts
| - so the template is explained in words and checked by the server, which is the only thing that
| can answer it honestly.
|
| shadcn's parts with Geist's rules (frontend.md §1.11): each field is a FieldSet named "Field 2",
| so its four inputs are heard as that field's; "Required" is one on/off choice, a Switch (Geist's
| Toggle); a row's other action, Remove Field, is in its ⋯ menu (owner, 2026-10-03). Adding past the
| limit stays where it is and says why, rather than greying out without a word. The printed form's
| keys are shown as inline code (Geist's Snippet rules).
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

/**
 * A row on the screen: the field as typed - its longest length as the text in its box, so a letter
 * typed there stays to be said wrong (frontend.md §1.7) - and a name for it that survives being
 * moved. Never posted.
 */
type Item = Omit<FieldRow, 'max_length'> & { max_length: string; uid: string };

/** What a row's boxes change. */
type Edit = Partial<Omit<Item, 'uid'>>;

function asRow(field: AddressFormatField): FieldRow {
    return {
        key: field.key,
        label_ar: field.labelAr,
        label_en: field.labelEn,
        required: field.required,
        max_length: field.maxLength,
    };
}

/**
 * The rows as the endpoint takes them, the longest length as a number. Only a whole number from 1
 * up is ever sent - the box's check stops Save on anything else - so it is sent exactly as the
 * number it reads.
 */
function posted(items: Item[]): FieldRow[] {
    return items.map(({ uid: _uid, max_length, ...row }) => ({ ...row, max_length: Number(max_length.trim()) }));
}

/** A field's key, as the domain spells one (AddressField::KEY). */
const KEY = /^[a-z][a-z0-9_]{1,39}$/;

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
    const { locale } = usePage<SharedProps>().props;
    const dndId = useId();
    const added = useRef(0);
    // dnd-kit reports the field over its own place the moment it is picked up; said then, it would
    // talk over "picked up" (found by stepping through it), so that first report is not said.
    const justPicked = useRef(false);
    // The same on the server and in the browser, so the first render matches; a row added later
    // gets a name of its own.
    const [items, setItems] = useState<Item[]>(() => startingFields.map((field, index) => ({ ...asRow(field), max_length: String(field.maxLength), uid: `field-${index}` })));
    const form = useForm({
        fields: startingFields.map(asRow),
        display_template: startingTemplate,
    });

    // Each box as typed (frontend.md §1.7), row by row in the order they are shown, with the
    // domain's rules: a key spelled as AddressField::KEY spells one; a longest length from 1 to the
    // limit the page is given (AddressField::LENGTH_MAX); labels of 1 to 60 characters
    // (AddressField::LABEL_MAX); a template of at most 2,000 (StoreAddressFormat::TEMPLATE_MAX),
    // which the request refuses empty. That each key appears once, and that the template names only
    // keys the form has, are the server's to say.
    const label = { required: true, length: { max: 60 } };
    const checks = useChecks([
        ...items.flatMap((item, index) => [
            {
                id: `key-${index}`,
                label: t('access::address_formats.field_key'),
                value: item.key,
                rules: { required: true, format: { pattern: KEY, key: 'access::address_formats.check.key' } },
            },
            { id: `length-${index}`, label: t('access::address_formats.max_length'), value: item.max_length, rules: { required: true, number: { min: 1, max: maxLength } } },
            { id: `label-ar-${index}`, label: t('access::address_formats.label_ar'), value: item.label_ar, rules: label },
            { id: `label-en-${index}`, label: t('access::address_formats.label_en'), value: item.label_en, rules: label },
        ]),
        { id: 'display_template', label: t('access::address_formats.template'), subject: t('access::address_formats.template_subject'), value: form.data.display_template, rules: { required: true, length: { max: 2000 } } },
    ]);
    const template = checks.box('display_template', form.errors.display_template);

    const sensors = useSensors(useSensor(MouseSensor, {}), useSensor(TouchSensor, {}), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

    function change(next: Item[]) {
        setItems(next);
        form.setData('fields', posted(next));
    }

    function edit(index: number, part: Edit) {
        change(items.map((each, at) => (at === index ? { ...each, ...part } : each)));
    }

    function onDragEnd(event: DragEndEvent) {
        const { active, over } = event;

        if (over === null || active.id === over.id) {
            return;
        }

        const from = items.findIndex((item) => item.uid === active.id);
        const to = items.findIndex((item) => item.uid === over.id);

        if (from >= 0 && to >= 0) {
            change(arrayMove(items, from, to));
        }
    }

    /** How a field is named aloud while it moves: its label in the page's language, else its key. */
    function spoken(id: UniqueIdentifier): string {
        const index = items.findIndex((item) => item.uid === id);
        const item = items[index];

        if (item === undefined) {
            return '';
        }

        const label = locale === 'ar' ? item.label_ar : item.label_en;

        return label !== '' ? label : item.key !== '' ? item.key : t('access::address_formats.field_number', { number: index + 1 });
    }

    const position = (id: UniqueIdentifier) => items.findIndex((item) => item.uid === id) + 1;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                checks.submit(() => form.post(`/admin/address-formats/${storeId}`, { preserveScroll: true }));
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

                {items.length === 0 ? (
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle>{t('access::address_formats.no_fields_title')}</EmptyTitle>
                            <EmptyDescription>{t('access::address_formats.no_fields')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DndContext
                        id={dndId}
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        modifiers={[restrictToVerticalAxis]}
                        onDragEnd={onDragEnd}
                        accessibility={{
                            screenReaderInstructions: { draggable: t('access::address_formats.drag_instructions') },
                            announcements: {
                                onDragStart: ({ active }) => {
                                    justPicked.current = true;

                                    return t('access::address_formats.drag_picked', { field: spoken(active.id) });
                                },
                                onDragOver: ({ active, over }) => {
                                    const first = justPicked.current;
                                    justPicked.current = false;

                                    return over === null || first
                                        ? undefined
                                        : t('access::address_formats.drag_moved', { field: spoken(active.id), position: position(over.id), total: items.length });
                                },
                                onDragEnd: ({ active, over }) =>
                                    over === null
                                        ? t('access::address_formats.drag_cancelled', { field: spoken(active.id) })
                                        : t('access::address_formats.drag_dropped', { field: spoken(active.id), position: position(over.id), total: items.length }),
                                onDragCancel: ({ active }) => t('access::address_formats.drag_cancelled', { field: spoken(active.id) }),
                            },
                        }}
                    >
                        <SortableContext items={items.map((item) => item.uid)} strategy={verticalListSortingStrategy}>
                            <div role="list" className="grid gap-4">
                                {items.map((item, index) => (
                                    <FieldItem
                                        key={item.uid}
                                        item={item}
                                        index={index}
                                        name={spoken(item.uid)}
                                        maxLength={maxLength}
                                        errors={form.errors}
                                        checks={checks}
                                        onEdit={(part) => edit(index, part)}
                                        onRemove={() => change(items.filter((_, at) => at !== index))}
                                    />
                                ))}
                            </div>
                        </SortableContext>
                    </DndContext>
                )}

                <ActionButton
                    type="button"
                    variant="outline"
                    className="w-fit"
                    data-test="add-field"
                    disabledReason={items.length >= maxFields ? t('access::address_formats.too_many_fields', { count: maxFields }) : undefined}
                    onClick={() => change([...items, { key: '', label_ar: '', label_en: '', required: false, max_length: '100', uid: `added-${added.current++}` }])}
                >
                    {t('access::address_formats.add_field')}
                </ActionButton>
            </section>

            <FieldSet className="material-base gap-3 p-5">
                <FieldLegend id="template-title" className="mb-0 text-heading-16 text-ink">
                    {t('access::address_formats.template')}
                </FieldLegend>
                <Field>
                    {/* The legend is the field's name: one heading, not a label dressed as one - so
                        the box is wired to its check by hand, as TextareaField would wire it, its
                        helper and its message tied to it (the review of batch A). */}
                    <Textarea
                        id="display_template"
                        aria-labelledby="template-title"
                        aria-describedby={describedBy('display_template', t('access::address_formats.template_hint'), template.message)}
                        aria-invalid={template.message ? true : undefined}
                        {...checked(template, {})}
                        dir="ltr"
                        rows={6}
                        className="font-mono"
                        value={form.data.display_template}
                        onChange={(event) => {
                            form.setData('display_template', event.target.value);
                            template.onType();
                        }}
                    />
                    <Messages id="display_template" helper={t('access::address_formats.template_hint')} error={template.message} check={template} />
                </Field>

                <p className="text-copy-13 text-ink-muted" dir="ltr">
                    <Keys text={t('access::address_formats.template_fields', { keys: MARK })} keys={items.map((item) => item.key).filter((key) => key !== '')} />
                </p>
            </FieldSet>

            <div className="grid gap-3">
                <p className="text-copy-13 text-ink-muted">{t('access::address_formats.existing_addresses')}</p>

                <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="save" className="w-fit">
                    {t('access::address_formats.save')}
                </ActionButton>
            </div>
        </form>
    );
}

/**
 * One field: its drag handle, its four inputs and Required, and its ⋯ menu. The handle is the only
 * thing that picks it up (dashboard-01's DragHandle), so typing in a field never starts a drag.
 */
function FieldItem({
    item,
    index,
    name,
    maxLength,
    errors,
    checks,
    onEdit,
    onRemove,
}: {
    item: Item;
    index: number;
    name: string;
    maxLength: number;
    errors: Record<string, string>;
    /** The editor's checks, which hold this row's four boxes under the ids below. */
    checks: Checks;
    onEdit: (part: Edit) => void;
    onRemove: () => void;
}) {
    const t = useTranslator();
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({
        id: item.uid,
        // dnd-kit names the handle's role "sortable", in English, on every page (the review of batch A).
        attributes: { roleDescription: t('access::address_formats.drag_role') },
    });
    const label = t('access::address_formats.field_number', { number: index + 1 });

    return (
        <div
            ref={setNodeRef}
            role="listitem"
            data-test={`field-${index}`}
            data-dragging={isDragging || undefined}
            className="relative z-0 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            style={{ transform: CSS.Transform.toString(transform), transition }}
        >
            {/* Named through aria-labelledby: the legend shares a row with the handle and the menu,
                and a fieldset takes its name only from a legend that is its own first child. */}
            <FieldSet className="material-base gap-4 p-5" aria-labelledby={`field-${index}-legend`}>
                <div className="flex items-center gap-2">
                    <Button
                        ref={setActivatorNodeRef}
                        {...attributes}
                        {...listeners}
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('access::address_formats.reorder', { field: name })}
                        title={t('access::address_formats.reorder', { field: name })}
                        className="cursor-grab text-muted-foreground hover:bg-transparent active:cursor-grabbing"
                        data-test={`drag-${index}`}
                    >
                        <GripVertical aria-hidden="true" />
                    </Button>
                    <FieldLegend id={`field-${index}-legend`} className="mb-0 text-heading-14 text-ink">
                        {label}
                    </FieldLegend>
                    <div className="ms-auto">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button type="button" variant="ghost" size="icon-sm" aria-label={`${t('ui.more_actions')}: ${label}`} title={t('ui.more_actions')} data-test={`menu-${index}`}>
                                    <MoreHorizontal aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="min-w-48">
                                <DropdownMenuItem variant="destructive" onSelect={onRemove} data-test={`remove-${index}`}>
                                    {t('access::address_formats.remove_field')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        id={`key-${index}`}
                        label={t('access::address_formats.field_key')}
                        helper={t('access::address_formats.field_key_hint')}
                        check={checks.box(`key-${index}`, fieldError(errors, index, 'key'))}
                        dir="ltr"
                        required
                        value={item.key}
                        onChange={(event) => onEdit({ key: event.target.value })}
                    />
                    {/* Text with a numeric keyboard, as every number input (frontend.md §1.8): a number
                        box drops a digit typed on an Arabic keyboard before it can be turned into 0-9.
                        What was typed stays in the box, a letter too, for its check to say (§1.7). */}
                    <TextField
                        id={`length-${index}`}
                        inputMode="numeric"
                        label={t('access::address_formats.max_length')}
                        helper={t('access::address_formats.max_length_hint', { count: maxLength })}
                        check={checks.box(`length-${index}`, fieldError(errors, index, 'max_length'))}
                        dir="ltr"
                        inputClassName="tw-figure"
                        required
                        value={item.max_length}
                        onChange={(event) => onEdit({ max_length: toLatinDigits(event.target.value) })}
                    />
                    <TextField
                        id={`label-ar-${index}`}
                        label={t('access::address_formats.label_ar')}
                        check={checks.box(`label-ar-${index}`, fieldError(errors, index, 'label_ar'))}
                        lang="ar"
                        dir="rtl"
                        required
                        value={item.label_ar}
                        onChange={(event) => onEdit({ label_ar: event.target.value })}
                    />
                    <TextField
                        id={`label-en-${index}`}
                        label={t('access::address_formats.label_en')}
                        check={checks.box(`label-en-${index}`, fieldError(errors, index, 'label_en'))}
                        lang="en"
                        dir="ltr"
                        required
                        value={item.label_en}
                        onChange={(event) => onEdit({ label_en: event.target.value })}
                    />
                </FieldGroup>

                <div className="border-t border-line pt-4">
                    <Field orientation="horizontal" className="w-fit">
                        <Switch
                            id={`required-${index}`}
                            checked={item.required}
                            onCheckedChange={(on) => onEdit({ required: on })}
                            className="data-[state=unchecked]:bg-ink-subtle"
                        />
                        <FieldLabel htmlFor={`required-${index}`} className="text-label-14 font-normal text-ink">
                            {t('access::address_formats.required')}
                        </FieldLabel>
                    </Field>
                </div>
            </FieldSet>
        </div>
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
