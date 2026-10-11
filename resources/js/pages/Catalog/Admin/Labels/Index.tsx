import { type RefObject, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { LabelData, LabelsPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, MoreButtonOff, StateBadge, nameIn, useAllStoresReason, useLocale } from '../parts';
import { DragHandle, MoveItems, SortableGroup, SortableGroups, useSavedOrder, useSortableRow } from '../SortableList';

/*
| The labels screen (catalog.md §1.8, §4.4 S5): «الشارات» (amendment 1(b)), each shown as a shopper
| sees it - Geist's Badge in its look - its name in the other language, its meaning, on how many
| products the stores show it, its state. A label's colour follows Geist's meanings, never a bare
| colour: Neutral, Information, Healthy, Warning or Error, Strong or Subtle - the Badge's ten
| (amendment 1(e)); its name one or two words, 30 characters (1(f)). One still shown on a product is
| not deleted. The list's order is the order on a card: dragged, saved when dropped, or a label moved
| to the top or the bottom from its ⋯ menu; a new label goes last (amendment 16(d)).
*/

const MEANINGS = [
    ['neutral', 'gray'],
    ['information', 'blue'],
    ['healthy', 'green'],
    ['warning', 'amber'],
    ['error', 'red'],
] as const;

type Meaning = (typeof MEANINGS)[number][0];

function toneOf(meaning: Meaning, subtle: boolean): Tone {
    const colour = MEANINGS.find(([name]) => name === meaning)?.[1] ?? 'gray';

    return (subtle ? `${colour}-subtle` : colour) as Tone;
}

function meaningOf(labelTone: string): { meaning: Meaning; subtle: boolean } {
    const subtle = labelTone.endsWith('-subtle');
    const colour = labelTone.replace('-subtle', '');

    return { meaning: MEANINGS.find(([, each]) => each === colour)?.[0] ?? 'neutral', subtle };
}

type Dialog = { action: 'add' | 'edit' | 'delete'; label: LabelData | null } | null;

export default function Index({ labels, mayChange }: LabelsPage) {
    const t = useTranslator();
    const reason = useAllStoresReason(mayChange);
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const add = () => setDialog({ action: 'add', label: null });
    const { order, move, toTop, toBottom } = useSavedOrder(
        labels.map((label) => label.id),
        '/admin/labels/order',
    );
    const byId = new Map(labels.map((label) => [label.id, label]));
    const rows = order.flatMap((id) => byId.get(id) ?? []);

    return (
        <AdminLayout
            title={t('catalog::admin_labels.title')}
            subtitle={t('catalog::admin_labels.subtitle')}
            action={
                <ActionButton type="button" onClick={add} disabledReason={reason} data-test="add-label">
                    {t('catalog::admin_labels.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                {labels.length === 0 ? (
                    <Empty className="material-base" data-test="labels-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_labels.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_labels.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={add} disabledReason={reason}>
                                {t('catalog::admin_labels.add')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <SortableGroups groups={{ labels: order }} labelOf={(id) => nameIn(locale, byId.get(id)?.nameAr ?? null, byId.get(id)?.nameEn ?? null)} onMove={(_, ids) => move(ids)}>
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('catalog::admin_labels.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t('catalog::admin.order.column')}</span>
                                        </TableHead>
                                        <TableHead>{t('catalog::admin_labels.column.label')}</TableHead>
                                        <TableHead>{t('catalog::admin_labels.column.other')}</TableHead>
                                        <TableHead>{t('catalog::admin_labels.column.meaning')}</TableHead>
                                        <TableHead className="text-end">{t('catalog::admin.column.products')}</TableHead>
                                        <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <SortableGroup id="labels" ids={order}>
                                        {rows.map((label, index) => (
                                            <Row
                                                key={label.id}
                                                label={label}
                                                reason={reason}
                                                first={index === 0}
                                                last={index === rows.length - 1}
                                                toTop={() => toTop(label.id)}
                                                toBottom={() => toBottom(label.id)}
                                                open={(action, trigger) => {
                                                    opener.current = trigger;
                                                    setDialog({ action, label });
                                                }}
                                            />
                                        ))}
                                    </SortableGroup>
                                </TableBody>
                            </Table>
                        </div>
                    </SortableGroups>
                )}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit') ? <LabelDialog label={dialog.label} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog !== null && dialog.action === 'delete' && dialog.label !== null ? <DeleteLabelDialog label={dialog.label} open onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function Row({
    label,
    reason,
    first,
    last,
    toTop,
    toBottom,
    open,
}: {
    label: LabelData;
    reason: string | undefined;
    first: boolean;
    last: boolean;
    toTop: () => void;
    toBottom: () => void;
    open: (action: 'edit' | 'delete', trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const { meaning, subtle } = meaningOf(label.tone);
    const name = nameIn(locale, label.nameAr, label.nameEn);
    const deleteReason = label.products > 0 ? t('catalog::admin_labels.reason.in_use') : null;
    const row = useSortableRow(label.id, reason !== undefined);

    return (
        <TableRow
            ref={row.ref}
            style={row.style}
            data-dragging={row.dragging || undefined}
            className="relative z-0 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            data-test={`label-${label.id}`}
        >
            <TableCell>
                <DragHandle handle={row.handle} label={name} reason={reason} test={`drag-label-${label.id}`} />
            </TableCell>
            <TableCell>
                <Badge className={tone(label.tone as Tone)} data-test="label-badge">
                    {name}
                </Badge>
            </TableCell>
            <TableCell dir={locale === 'ar' ? 'ltr' : 'rtl'}>{locale === 'ar' ? label.nameEn : label.nameAr}</TableCell>
            <TableCell>
                {t(`catalog::admin_labels.meaning.${meaning}`)} · {subtle ? t('catalog::admin_labels.strength.subtle') : t('catalog::admin_labels.strength.strong')}
            </TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, label.products)}</TableCell>
            <TableCell>
                <StateBadge active={label.active} />
            </TableCell>
            <TableCell className="text-end">
                {reason !== undefined ? (
                    <MoreButtonOff name={name} reason={reason} />
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={name} busy={busy} data-test={`label-actions-${label.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-label">
                                {t('catalog::admin_labels.edit')}
                            </DropdownMenuItem>
                            <MoveItems first={first} last={last} onTop={toTop} onBottom={toBottom} />
                            <DropdownMenuItem
                                disabled={busy}
                                onSelect={() => router.post(`/admin/labels/${label.id}/${label.active ? 'deactivate' : 'activate'}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                                data-test={label.active ? 'deactivate-label' : 'activate-label'}
                            >
                                {label.active ? t('catalog::admin_labels.deactivate') : t('catalog::admin_labels.activate')}
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
                                data-test="delete-label"
                            >
                                <span className="grid gap-0.5">
                                    <span className={deleteReason !== null ? 'opacity-60' : undefined}>{t('catalog::admin_labels.delete')}</span>
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

type LabelForm = { name_ar: string; name_en: string; meaning: Meaning; subtle: boolean };

function LabelDialog({
    label,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    label: LabelData | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const initial = (): LabelForm => {
        const look = meaningOf(label?.tone ?? 'gray');

        return { name_ar: label?.nameAr ?? '', name_en: label?.nameEn ?? '', meaning: look.meaning, subtle: label === null ? true : look.subtle };
    };
    const form = useForm<LabelForm>(initial());
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
        }
    }, [open, label?.id]);

    const title = label === null ? t('catalog::admin_labels.add') : t('catalog::admin_labels.edit_title');
    const preview = nameIn(locale, form.data.name_ar, form.data.name_en) || t('catalog::admin_labels.preview_empty');
    // Each box as typed (frontend.md §1.7), with the domain's rules: names of up to 30 characters
    // (Label::NAME_MAX) of one or two words - what the spaces separate (Label::checkWords, amendment
    // 1(f)). A new label goes last; it has no place to type (amendment 16(d)).
    const name = { required: true, length: { max: 30 }, format: { pattern: /^\S+(\s+\S+)?$/u, key: 'catalog::admin_labels.check.words' } };
    const checks = useChecks([
        { id: 'label-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name },
        { id: 'label-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name },
    ]);

    function submit() {
        form.transform((data) => ({ name_ar: data.name_ar, name_en: data.name_en, tone: toneOf(data.meaning, data.subtle) }));
        checks.submit(() => form.post(label === null ? '/admin/labels' : `/admin/labels/${label.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t('catalog::admin_labels.body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-label">
                    {label === null ? title : t('catalog::admin_labels.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField id="label-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} helper={t('catalog::admin_labels.name_helper')} value={form.data.name_ar} check={checks.box('label-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="label-name-ar" />
                <TextField id="label-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} helper={t('catalog::admin_labels.name_helper')} value={form.data.name_en} check={checks.box('label-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="label-name-en" />

                <FieldSet>
                    <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_labels.column.meaning')}</FieldLegend>
                    <RadioGroup value={form.data.meaning} onValueChange={(value) => form.setData('meaning', value as Meaning)} className="grid gap-2 sm:grid-cols-2">
                        {MEANINGS.map(([meaning]) => (
                            <div key={meaning} className="flex items-center gap-2">
                                <RadioGroupItem id={`meaning-${meaning}`} value={meaning} data-test={`meaning-${meaning}`} />
                                <Label htmlFor={`meaning-${meaning}`} className="text-copy-14">
                                    {t(`catalog::admin_labels.meaning.${meaning}`)}
                                </Label>
                            </div>
                        ))}
                    </RadioGroup>
                    {errors.tone ? <FieldError>{errors.tone}</FieldError> : null}
                </FieldSet>

                <FieldSet>
                    <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_labels.strength.title')}</FieldLegend>
                    <RadioGroup value={form.data.subtle ? 'subtle' : 'strong'} onValueChange={(value) => form.setData('subtle', value === 'subtle')} className="flex gap-4">
                        {(['strong', 'subtle'] as const).map((strength) => (
                            <div key={strength} className="flex items-center gap-2">
                                <RadioGroupItem id={`strength-${strength}`} value={strength} data-test={`strength-${strength}`} />
                                <Label htmlFor={`strength-${strength}`} className="text-copy-14">
                                    {t(`catalog::admin_labels.strength.${strength}`)}
                                </Label>
                            </div>
                        ))}
                    </RadioGroup>
                </FieldSet>

                <p className="flex items-center gap-2 text-copy-13 text-ink-muted">
                    {t('catalog::admin_labels.preview')}
                    <Badge className={tone(toneOf(form.data.meaning, form.data.subtle))} data-test="label-preview">
                        {preview}
                    </Badge>
                </p>
            </div>
        </PanelDialog>
    );
}

function DeleteLabelDialog({ label, open, onOpenChange, returnFocusTo }: { label: LabelData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_labels.delete_title')}
            description={t('catalog::admin_labels.delete_body', { name: nameIn(locale, label.nameAr, label.nameEn) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/labels/${label.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-label"
                >
                    {t('catalog::admin_labels.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
