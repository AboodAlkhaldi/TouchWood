import { type RefObject, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { FieldError } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { AttributeData, VariationData, VariationsPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, MoreButtonOff, NameCells, NameHeads, StateBadge, nameIn, useAllStoresReason, useLocale } from '../parts';
import { SortableList } from '../SortableList';

/*
| The variations screen (catalog.md §1.7, §4.4 S4): the attribute sets - the design's own name,
| "Variations": attribute sets, measurement and finish, that generate variants. Each is one to ten
| variant-making attributes, in order; once variants are built on one, its attributes stay
| (amendment 3(k)) while its name may still change. Changes need `catalog.attribute.manage` with All
| stores (§3).
*/

type Dialog = { action: 'add' | 'edit' | 'delete'; variation: VariationData | null } | null;

export default function Index({ variations, attributes, mayChange }: VariationsPage) {
    const t = useTranslator();
    const reason = useAllStoresReason(mayChange);
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const named = new Map(attributes.map((attribute) => [attribute.id, nameIn(locale, attribute.nameAr, attribute.nameEn)]));
    const add = () => setDialog({ action: 'add', variation: null });

    return (
        <AdminLayout
            title={t('catalog::admin_attributes.variations.title')}
            subtitle={t('catalog::admin_attributes.variations.subtitle')}
            action={
                <ActionButton type="button" onClick={add} disabledReason={reason} data-test="add-variation">
                    {t('catalog::admin_attributes.variations.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                {variations.length === 0 ? (
                    <Empty className="material-base" data-test="variations-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_attributes.variations.empty_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_attributes.variations.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={add} disabledReason={reason}>
                                {t('catalog::admin_attributes.variations.add')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('catalog::admin_attributes.variations.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <NameHeads locale={locale} />
                                    <TableHead>{t('catalog::admin_attributes.variations.column.attributes')}</TableHead>
                                    <TableHead className="text-end">{t('catalog::admin.column.products')}</TableHead>
                                    <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {variations.map((variation) => (
                                    <Row
                                        key={variation.id}
                                        variation={variation}
                                        named={named}
                                        reason={reason}
                                        open={(action, trigger) => {
                                            opener.current = trigger;
                                            setDialog({ action, variation });
                                        }}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit') ? (
                <VariationDialog variation={dialog.variation} attributes={attributes} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
            {dialog !== null && dialog.action === 'delete' && dialog.variation !== null ? (
                <DeleteVariationDialog variation={dialog.variation} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
        </AdminLayout>
    );
}

function Row({ variation, named, reason, open }: { variation: VariationData; named: Map<string, string>; reason: string | undefined; open: (action: 'edit' | 'delete', trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const deleteReason = variation.products > 0 ? t('catalog::admin_attributes.reason.variation_in_use') : null;

    return (
        <TableRow data-test={`variation-${variation.id}`}>
            <NameCells locale={locale} ar={variation.nameAr} en={variation.nameEn} />
            <TableCell>
                <span className="flex flex-wrap items-center gap-1.5">
                    {variation.attributeIds.map((id, index) => (
                        <Badge key={id} variant="outline" className="h-6 text-label-12">
                            <span className="tw-figure text-ink-muted">{figure(locale, index + 1)}</span>
                            {named.get(id) ?? id}
                        </Badge>
                    ))}
                    {variation.builtOn ? (
                        <Badge className={tone('blue-subtle')} data-test="variation-in-use">
                            {t('catalog::admin_attributes.variations.in_use')}
                        </Badge>
                    ) : null}
                </span>
            </TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, variation.products)}</TableCell>
            <TableCell>
                <StateBadge active={variation.active} />
            </TableCell>
            <TableCell className="text-end">
                {reason !== undefined ? (
                    <MoreButtonOff name={nameIn(locale, variation.nameAr, variation.nameEn)} reason={reason} />
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={nameIn(locale, variation.nameAr, variation.nameEn)} busy={busy} data-test={`variation-actions-${variation.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-variation">
                                {t('catalog::admin_attributes.variations.edit')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={busy}
                                onSelect={() =>
                                    router.post(`/admin/variations/${variation.id}/${variation.active ? 'deactivate' : 'activate'}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                                }
                                data-test={variation.active ? 'deactivate-variation' : 'activate-variation'}
                            >
                                {variation.active ? t('catalog::admin_attributes.variations.deactivate') : t('catalog::admin_attributes.variations.activate')}
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
                                data-test="delete-variation"
                            >
                                <span className="grid gap-0.5">
                                    <span className={deleteReason !== null ? 'opacity-60' : undefined}>{t('catalog::admin_attributes.variations.delete')}</span>
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

type VariationForm = { name_ar: string; name_en: string; attribute_ids: string[] };

function VariationDialog({
    variation,
    attributes,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    variation: VariationData | null;
    attributes: AttributeData[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const initial = (): VariationForm => ({ name_ar: variation?.nameAr ?? '', name_en: variation?.nameEn ?? '', attribute_ids: variation?.attributeIds ?? [] });
    const form = useForm<VariationForm>(initial());
    const [adding, setAdding] = useState('');
    // Its attributes stay while variants are built on it (amendment 3(k)); its name may change.
    const locked = variation?.builtOn ?? false;
    const named = new Map(attributes.map((attribute) => [attribute.id, nameIn(locale, attribute.nameAr, attribute.nameEn)]));
    // Picked from the active variant-making attributes not in it yet; one held since may stay.
    const offered = attributes.filter((attribute) => attribute.kind === 'VARIANT' && attribute.active && !form.data.attribute_ids.includes(attribute.id));

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
            setAdding('');
        }
    }, [open, variation?.id]);

    const title = variation === null ? t('catalog::admin_attributes.variations.add') : t('catalog::admin_attributes.variations.edit_title');
    const errors = form.errors as Record<string, string | undefined>;

    function submit() {
        form.post(variation === null ? '/admin/variations' : `/admin/variations/${variation.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    }

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t('catalog::admin_attributes.variations.body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} onClick={submit} data-test="confirm-variation">
                    {variation === null ? title : t('catalog::admin_attributes.variations.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField id="variation-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} error={form.errors.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="variation-name-ar" />
                    <TextField id="variation-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} value={form.data.name_en} error={form.errors.name_en} onChange={(event) => form.setData('name_en', event.target.value)} data-test="variation-name-en" />
                </div>

                <div className="grid gap-2">
                    <p className="text-label-14 text-ink">{t('catalog::admin_attributes.variations.column.attributes')}</p>
                    {locked ? (
                        <Note>{t('catalog::admin_attributes.reason.built_on')}</Note>
                    ) : (
                        <p className="text-copy-13 text-ink-muted">{t('catalog::admin_attributes.variations.attributes_helper')}</p>
                    )}
                    {form.data.attribute_ids.length === 0 ? null : locked ? (
                        <ol className="grid gap-1 ps-5 text-copy-14 text-ink">
                            {form.data.attribute_ids.map((id) => (
                                <li key={id} className="list-decimal">
                                    {named.get(id) ?? id}
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <SortableList
                            testPrefix="member"
                            items={form.data.attribute_ids.map((id) => ({
                                id,
                                label: named.get(id) ?? id,
                                extra: (
                                    <Button type="button" variant="ghost" size="sm" onClick={() => form.setData('attribute_ids', form.data.attribute_ids.filter((each) => each !== id))} data-test={`remove-member-${id}`}>
                                        {t('catalog::admin_attributes.variations.remove')}
                                    </Button>
                                ),
                            }))}
                            onChange={(ids) => form.setData('attribute_ids', ids)}
                        />
                    )}
                    {errors.attribute_ids ? <FieldError>{errors.attribute_ids}</FieldError> : null}
                </div>

                {locked || offered.length === 0 || form.data.attribute_ids.length >= 10 ? null : (
                    <div className="flex items-end gap-2">
                        <SelectField id="variation-add-attribute" className="flex-1" label={t('catalog::admin_attributes.variations.pick')} value={adding} onChange={(event) => setAdding(event.target.value)} data-test="variation-pick">
                            <NativeSelectOption value="">{t('catalog::admin.choose')}</NativeSelectOption>
                            {offered.map((attribute) => (
                                <NativeSelectOption key={attribute.id} value={attribute.id}>
                                    {nameIn(locale, attribute.nameAr, attribute.nameEn)}
                                </NativeSelectOption>
                            ))}
                        </SelectField>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={adding === ''}
                            onClick={() => {
                                form.setData('attribute_ids', [...form.data.attribute_ids, adding]);
                                setAdding('');
                            }}
                            data-test="variation-add-member"
                        >
                            {t('catalog::admin_attributes.variations.add_member')}
                        </Button>
                    </div>
                )}
            </div>
        </PanelDialog>
    );
}

function DeleteVariationDialog({ variation, open, onOpenChange, returnFocusTo }: { variation: VariationData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_attributes.variations.delete_title')}
            description={t('catalog::admin_attributes.variations.delete_body', { name: nameIn(locale, variation.nameAr, variation.nameEn) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/variations/${variation.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-variation"
                >
                    {t('catalog::admin_attributes.variations.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
