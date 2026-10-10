import { type RefObject, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { AttributePage, ValueData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, MoreButtonOff, NameCells, NameHeads, StateBadge, nameIn, useAllStoresReason, useLocale } from '../parts';
import { DragHandle, MoveItems, SortableGroup, SortableRows, useSavedOrder, useSortableRow } from '../SortableList';
import { AttributeFields, AttributeMenu, DeleteAttributeDialog, attributeForm } from './AttributeDialog';

/*
| One attribute (catalog.md §1.7, §4.4 S3): its details as a form, then its values in order - each
| named in both languages, never the same as another of this attribute ignoring case (`NameTaken`),
| and on a colour attribute each with its swatch, chosen with the browser's colour picker beside its
| `#rrggbb`. A "details only" attribute has no values: variants carry their own text or number for it.
| The values' order is the order of the shop's pickers: dragged, saved when dropped, or a value moved
| to the top or the bottom from its ⋯ menu; a new value goes last (amendment 16(d)).
| For a reader without All stores, everything stays in sight, out of reach, with that reason (P2).
*/

type Dialog = { action: 'delete' | 'add-value' | 'edit-value' | 'delete-value'; value: ValueData | null } | null;

export default function Show({ attribute, values, mayChange }: AttributePage) {
    const t = useTranslator();
    const locale = useLocale();
    const name = nameIn(locale, attribute.nameAr, attribute.nameEn);
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const hasValues = attribute.kind !== 'INFORMATIONAL';
    const reason = useAllStoresReason(mayChange);
    const form = useForm(attributeForm(attribute));
    const { order, move, toTop, toBottom } = useSavedOrder(
        values.map((value) => value.id),
        `/admin/attributes/${attribute.id}/values/order`,
    );
    const byId = new Map(values.map((value) => [value.id, value]));
    const rows = order.flatMap((id) => byId.get(id) ?? []);

    // The form follows the attribute as the server has it after every change made here.
    useEffect(() => {
        form.setDefaults(attributeForm(attribute));
        form.reset();
    }, [attribute]);

    return (
        <AdminLayout
            title={name}
            subtitle={t(`catalog::admin_attributes.kind.${attribute.kind}`)}
            breadcrumbs={[{ label: t('catalog::admin_attributes.title'), href: '/admin/attributes' }]}
            action={
                <div className="flex items-center gap-2">
                    {hasValues ? (
                        <ActionButton type="button" disabledReason={reason} onClick={(event) => ((opener.current = event.currentTarget), setDialog({ action: 'add-value', value: null }))} data-test="add-value">
                            {t('catalog::admin_attributes.value.add')}
                        </ActionButton>
                    ) : null}
                    {reason !== undefined ? (
                        <MoreButtonOff name={name} reason={reason} />
                    ) : (
                        <AttributeMenu
                            attribute={attribute}
                            name={name}
                            open={(action, trigger) => {
                                opener.current = trigger;
                                setDialog({ action, value: null });
                            }}
                        />
                    )}
                </div>
            }
        >
            <div className="grid gap-6">
                <FormError />
                <Card className="material-base border-0" data-test="attribute-details">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-heading-16 text-ink">
                            {t('catalog::admin_attributes.details')}
                            <StateBadge active={attribute.active} />
                        </CardTitle>
                        <CardDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_attributes.edit_body')}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <AttributeFields form={form} attribute={attribute} off={reason !== undefined} />
                    </CardContent>
                    <CardFooter className="justify-end">
                        <ActionButton
                            loading={form.processing}
                            disabledReason={reason}
                            onClick={() => form.post(`/admin/attributes/${attribute.id}`, { preserveScroll: true })}
                            data-test="save-attribute"
                        >
                            {t('catalog::admin_attributes.save')}
                        </ActionButton>
                    </CardFooter>
                </Card>

                {!hasValues ? (
                    <Note data-test="no-values">{t('catalog::admin_attributes.value.none_kind')}</Note>
                ) : values.length === 0 ? (
                    <Empty className="material-base" data-test="values-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_attributes.value.empty_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_attributes.value.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" disabledReason={reason} onClick={() => setDialog({ action: 'add-value', value: null })}>
                                {t('catalog::admin_attributes.value.add')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <SortableRows groups={{ values: order }} labelOf={(id) => nameIn(locale, byId.get(id)?.nameAr ?? null, byId.get(id)?.nameEn ?? null)} onMove={(_, ids) => move(ids)}>
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('catalog::admin_attributes.value.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t('catalog::admin.order.column')}</span>
                                        </TableHead>
                                        <NameHeads locale={locale} />
                                        {attribute.isColour ? <TableHead>{t('catalog::admin_attributes.value.swatch')}</TableHead> : null}
                                        <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <SortableGroup id="values" ids={order}>
                                        {rows.map((value, index) => (
                                            <ValueRow
                                                key={value.id}
                                                value={value}
                                                colour={attribute.isColour}
                                                reason={reason}
                                                first={index === 0}
                                                last={index === rows.length - 1}
                                                toTop={() => toTop(value.id)}
                                                toBottom={() => toBottom(value.id)}
                                                open={(action, trigger) => {
                                                    opener.current = trigger;
                                                    setDialog({ action, value });
                                                }}
                                            />
                                        ))}
                                    </SortableGroup>
                                </TableBody>
                            </Table>
                        </div>
                    </SortableRows>
                )}
            </div>

            {dialog?.action === 'delete' ? <DeleteAttributeDialog attribute={attribute} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'add-value' || dialog?.action === 'edit-value' ? (
                <ValueDialog attributeId={attribute.id} colour={attribute.isColour} value={dialog.value} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
            {dialog?.action === 'delete-value' && dialog.value !== null ? <DeleteValueDialog value={dialog.value} open onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function ValueRow({
    value,
    colour,
    reason,
    first,
    last,
    toTop,
    toBottom,
    open,
}: {
    value: ValueData;
    colour: boolean;
    reason: string | undefined;
    first: boolean;
    last: boolean;
    toTop: () => void;
    toBottom: () => void;
    open: (action: 'edit-value' | 'delete-value', trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const deleteReason = value.inUse ? t('catalog::admin_attributes.reason.value_in_use') : null;
    const row = useSortableRow(value.id, reason !== undefined);

    return (
        <TableRow
            ref={row.ref}
            style={row.style}
            data-dragging={row.dragging || undefined}
            className="relative z-0 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            data-test={`value-${value.id}`}
        >
            <TableCell>
                <DragHandle handle={row.handle} label={nameIn(locale, value.nameAr, value.nameEn)} reason={reason} test={`drag-value-${value.id}`} />
            </TableCell>
            <NameCells locale={locale} ar={value.nameAr} en={value.nameEn} />
            {colour ? (
                <TableCell>
                    {value.swatch !== null ? (
                        <span className="flex items-center gap-2">
                            {/* The swatch is data, the colour staff chose, drawn as it is. */}
                            <span className="size-5 rounded-sm border border-line" style={{ backgroundColor: value.swatch }} aria-hidden="true" />
                            <span className="tw-figure text-copy-13" dir="ltr">
                                {value.swatch}
                            </span>
                        </span>
                    ) : (
                        '—'
                    )}
                </TableCell>
            ) : null}
            <TableCell>
                <StateBadge active={value.active} />
            </TableCell>
            <TableCell className="text-end">
                {reason !== undefined ? (
                    <MoreButtonOff name={nameIn(locale, value.nameAr, value.nameEn)} reason={reason} />
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={nameIn(locale, value.nameAr, value.nameEn)} busy={busy} data-test={`value-actions-${value.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <DropdownMenuItem onSelect={() => open('edit-value', more.current)} data-test="edit-value">
                                {t('catalog::admin_attributes.value.edit')}
                            </DropdownMenuItem>
                            <MoveItems first={first} last={last} onTop={toTop} onBottom={toBottom} />
                            <DropdownMenuItem
                                disabled={busy}
                                onSelect={() =>
                                    router.post(`/admin/attribute-values/${value.id}/${value.active ? 'deactivate' : 'activate'}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                                }
                                data-test={value.active ? 'deactivate-value' : 'activate-value'}
                            >
                                {value.active ? t('catalog::admin_attributes.value.deactivate') : t('catalog::admin_attributes.value.activate')}
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

                                    open('delete-value', more.current);
                                }}
                                data-test="delete-value"
                            >
                                <span className="grid gap-0.5">
                                    <span className={deleteReason !== null ? 'opacity-60' : undefined}>{t('catalog::admin_attributes.value.delete')}</span>
                                    {deleteReason !== null ? <span className="text-copy-12 text-ink-muted">{deleteReason}</span> : null}
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </TableCell>
        </TableRow>
    );
}

type ValueForm = { name_ar: string; name_en: string; swatch: string };

/** A swatch as the domain keeps it: a hash and six hexadecimal digits (§1.7). */
const SWATCH = /^#[0-9a-f]{6}$/i;

function ValueDialog({
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

    function submit() {
        form.transform((data) => (colour ? data : { ...data, swatch: '' }));
        form.post(value === null ? `/admin/attributes/${attributeId}/values` : `/admin/attribute-values/${value.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
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
                <ActionButton loading={form.processing} onClick={submit} data-test="confirm-value">
                    {value === null ? title : t('catalog::admin_attributes.value.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField id="value-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} error={form.errors.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="value-name-ar" />
                <TextField id="value-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} value={form.data.name_en} error={form.errors.name_en} onChange={(event) => form.setData('name_en', event.target.value)} data-test="value-name-en" />
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
                            <Input
                                id="value-swatch"
                                dir="ltr"
                                className="tw-figure max-w-32"
                                value={form.data.swatch}
                                aria-invalid={form.errors.swatch ? true : undefined}
                                aria-describedby="value-swatch-helper"
                                onChange={(event) => form.setData('swatch', toLatinDigits(event.target.value))}
                                data-test="value-swatch"
                            />
                        </div>
                        <FieldDescription id="value-swatch-helper">{t('catalog::admin_attributes.value.swatch_helper')}</FieldDescription>
                        {form.errors.swatch ? <FieldError>{form.errors.swatch}</FieldError> : null}
                    </Field>
                ) : null}
            </div>
        </PanelDialog>
    );
}

function DeleteValueDialog({ value, open, onOpenChange, returnFocusTo }: { value: ValueData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
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
