import { type RefObject, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { WarrantiesPage, WarrantyData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { marksLength } from '../marks';
import { MarksField, MoreButton, MoreButtonOff, NameCells, NameHeads, StateBadge, nameIn, useAllStoresReason, useLocale } from '../parts';

/*
| The warranties screen (catalog.md §1.9, §4.4 S6): a list a product carries at most one of - each
| named in both languages, with a period of 1 to 600 months or for life, and its terms in both
| languages, typed with the products file's marks (P1). One a product carries is not deleted.
*/

type Dialog = { action: 'add' | 'edit' | 'delete'; warranty: WarrantyData | null } | null;

export default function Index({ warranties, mayChange }: WarrantiesPage) {
    const t = useTranslator();
    const reason = useAllStoresReason(mayChange);
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const add = () => setDialog({ action: 'add', warranty: null });

    return (
        <AdminLayout
            title={t('catalog::admin_warranties.title')}
            subtitle={t('catalog::admin_warranties.subtitle')}
            action={
                <ActionButton type="button" onClick={add} disabledReason={reason} data-test="add-warranty">
                    {t('catalog::admin_warranties.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                {warranties.length === 0 ? (
                    <Empty className="material-base" data-test="warranties-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_warranties.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_warranties.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={add} disabledReason={reason}>
                                {t('catalog::admin_warranties.add')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('catalog::admin_warranties.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <NameHeads locale={locale} />
                                    <TableHead>{t('catalog::admin_warranties.column.period')}</TableHead>
                                    <TableHead className="text-end">{t('catalog::admin.column.products')}</TableHead>
                                    <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {warranties.map((warranty) => (
                                    <Row
                                        key={warranty.id}
                                        warranty={warranty}
                                        reason={reason}
                                        open={(action, trigger) => {
                                            opener.current = trigger;
                                            setDialog({ action, warranty });
                                        }}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit') ? <WarrantyDialog warranty={dialog.warranty} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog !== null && dialog.action === 'delete' && dialog.warranty !== null ? <DeleteWarrantyDialog warranty={dialog.warranty} open onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function Row({ warranty, reason, open }: { warranty: WarrantyData; reason: string | undefined; open: (action: 'edit' | 'delete', trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const deleteReason = warranty.products > 0 ? t('catalog::admin_warranties.reason.in_use') : null;

    return (
        <TableRow data-test={`warranty-${warranty.id}`}>
            <NameCells locale={locale} ar={warranty.nameAr} en={warranty.nameEn} />
            <TableCell data-test="warranty-period">{warranty.periodMonths === null ? t('catalog::admin_warranties.lifetime') : t(`catalog::admin_warranties.months.${new Intl.PluralRules(locale).select(warranty.periodMonths)}`, { count: figure(locale, warranty.periodMonths) })}</TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, warranty.products)}</TableCell>
            <TableCell>
                <StateBadge active={warranty.active} />
            </TableCell>
            <TableCell className="text-end">
                {reason !== undefined ? (
                    <MoreButtonOff name={nameIn(locale, warranty.nameAr, warranty.nameEn)} reason={reason} />
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={nameIn(locale, warranty.nameAr, warranty.nameEn)} busy={busy} data-test={`warranty-actions-${warranty.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-warranty">
                                {t('catalog::admin_warranties.edit')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={busy}
                                onSelect={() =>
                                    router.post(`/admin/warranties/${warranty.id}/${warranty.active ? 'deactivate' : 'activate'}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                                }
                                data-test={warranty.active ? 'deactivate-warranty' : 'activate-warranty'}
                            >
                                {warranty.active ? t('catalog::admin_warranties.deactivate') : t('catalog::admin_warranties.activate')}
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
                                data-test="delete-warranty"
                            >
                                <span className="grid gap-0.5">
                                    <span className={deleteReason !== null ? 'opacity-60' : undefined}>{t('catalog::admin_warranties.delete')}</span>
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

type WarrantyForm = { name_ar: string; name_en: string; period_months: string; lifetime: boolean; terms_ar: string; terms_en: string };

function WarrantyDialog({ warranty, open, onOpenChange, returnFocusTo }: { warranty: WarrantyData | null; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const initial = (): WarrantyForm => ({
        name_ar: warranty?.nameAr ?? '',
        name_en: warranty?.nameEn ?? '',
        period_months: warranty?.periodMonths === null || warranty === null ? '' : String(warranty.periodMonths),
        lifetime: warranty !== null && warranty.periodMonths === null,
        terms_ar: warranty?.termsAr ?? '',
        terms_en: warranty?.termsEn ?? '',
    });
    const form = useForm<WarrantyForm>(initial());

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
        }
    }, [open, warranty?.id]);

    const title = warranty === null ? t('catalog::admin_warranties.add') : t('catalog::admin_warranties.edit_title');
    // Each box as typed (frontend.md §1.7), with the domain's rules: a name of up to 100 characters
    // (Warranty::NAME_MAX), terms of up to 5,000 (Warranty::TERMS_MAX, counted as StructuredText
    // counts them), a period of 1 to 600 months unless for life (WarrantyPeriod).
    const name = { required: true, length: { max: 100 } };
    const terms = { required: true, length: { max: 5000, of: marksLength } };
    const checks = useChecks([
        { id: 'warranty-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name },
        { id: 'warranty-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name },
        { id: 'warranty-period', label: t('catalog::admin_warranties.field.period'), value: form.data.period_months, rules: { required: true, number: { min: 1, max: 600 } }, off: form.data.lifetime },
        { id: 'warranty-terms-ar', label: t('catalog::admin_warranties.field.terms_ar'), value: form.data.terms_ar, rules: terms },
        { id: 'warranty-terms-en', label: t('catalog::admin_warranties.field.terms_en'), value: form.data.terms_en, rules: terms },
    ]);

    function submit() {
        checks.submit(() => form.post(warranty === null ? '/admin/warranties' : `/admin/warranties/${warranty.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t('catalog::admin_warranties.body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-warranty">
                    {warranty === null ? title : t('catalog::admin_warranties.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField id="warranty-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} check={checks.box('warranty-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="warranty-name-ar" />
                    <TextField id="warranty-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} value={form.data.name_en} check={checks.box('warranty-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="warranty-name-en" />
                </div>
                <div className="flex flex-wrap items-end gap-4">
                    <TextField
                        id="warranty-period"
                        dir="ltr"
                        inputMode="numeric"
                        className="max-w-40"
                        inputClassName="tw-figure"
                        label={t('catalog::admin_warranties.field.period')}
                        helper={t('catalog::admin_warranties.field.period_helper')}
                        value={form.data.period_months}
                        check={checks.box('warranty-period', form.errors.period_months)}
                        disabled={form.data.lifetime}
                        onChange={(event) => form.setData('period_months', toLatinDigits(event.target.value))}
                        data-test="warranty-period"
                    />
                    <div className="flex items-center gap-2 pb-2">
                        <Checkbox id="warranty-lifetime" checked={form.data.lifetime} onCheckedChange={(on) => form.setData('lifetime', on === true)} data-test="warranty-lifetime" />
                        <Label htmlFor="warranty-lifetime" className="text-copy-14">
                            {t('catalog::admin_warranties.lifetime')}
                        </Label>
                    </div>
                </div>
                <MarksField id="warranty-terms-ar" dir="rtl" label={t('catalog::admin_warranties.field.terms_ar')} value={form.data.terms_ar} check={checks.box('warranty-terms-ar', form.errors.terms_ar)} onChange={(value) => form.setData('terms_ar', value)} />
                <MarksField id="warranty-terms-en" dir="ltr" label={t('catalog::admin_warranties.field.terms_en')} value={form.data.terms_en} check={checks.box('warranty-terms-en', form.errors.terms_en)} onChange={(value) => form.setData('terms_en', value)} />
            </div>
        </PanelDialog>
    );
}

function DeleteWarrantyDialog({ warranty, open, onOpenChange, returnFocusTo }: { warranty: WarrantyData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_warranties.delete_title')}
            description={t('catalog::admin_warranties.delete_body', { name: nameIn(locale, warranty.nameAr, warranty.nameEn) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/warranties/${warranty.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-warranty"
                >
                    {t('catalog::admin_warranties.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
