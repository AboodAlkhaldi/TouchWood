import { lazy, type RefObject, Suspense, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { PhoneCards } from '@/components/PhoneCards';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { LabelData, LabelsPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButton, MoreButtonOff, StateBadge, nameIn, useAllStoresReason, useLocale } from '../parts';
import { DragHandle, MoveItems, SortableGroup, SortableGroups, useSavedOrder, useSortableRow } from '../SortableList';
import { meaningOf } from './meanings';

// The add and edit dialog - its form, its checks as typed, the meaning's choices - loads when it is first opened, as the
// brands' does, which leaves the page room for its phone cards (frontend.md §5, the page budget).
const LabelDialog = lazy(() => import('./LabelDialog').then((module) => ({ default: module.LabelDialog })));

/*
| The labels screen (catalog.md §1.8, §4.4 S5): «الشارات» (amendment 1(b)), each shown as a shopper
| sees it - Geist's Badge in its look - its name in the other language, its meaning, on how many
| products the stores show it, its state. A label's colour follows Geist's meanings, never a bare
| colour: Neutral, Information, Healthy, Warning or Error, Strong or Subtle - the Badge's ten
| (amendment 1(e)); its name one or two words, 30 characters (1(f)). One still shown on a product is
| not deleted. The list's order is the order on a card: dragged, saved when dropped, or a label moved
| to the top or the bottom from its ⋯ menu; a new label goes last (amendment 16(d)).
*/

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
                        <div className="material-base hidden overflow-x-auto sm:block">
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
                        {/* On a phone each label a card (round 3 #15), shown as a shopper sees it; its place moves from its menu. */}
                        <PhoneCards
                            test="label"
                            cards={rows.map((label, index) => ({
                                id: label.id,
                                title: <Badge className={tone(label.tone as Tone)}>{nameIn(locale, label.nameAr, label.nameEn)}</Badge>,
                                badge: <StateBadge active={label.active} />,
                                lines: [
                                    <span key="o" dir={locale === 'ar' ? 'ltr' : 'rtl'}>
                                        {locale === 'ar' ? label.nameEn : label.nameAr}
                                    </span>,
                                    <span key="p" className="tw-figure">{`${t('catalog::admin.column.products')}: ${figure(locale, label.products)}`}</span>,
                                ],
                                menu: (
                                    <LabelMenu
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
                                        test={`label-card-actions-${label.id}`}
                                    />
                                ),
                            }))}
                        />
                    </SortableGroups>
                )}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit') ? (
                <Suspense fallback={null}>
                    <LabelDialog label={dialog.label} open onOpenChange={close} returnFocusTo={opener} />
                </Suspense>
            ) : null}
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
    const { meaning, subtle } = meaningOf(label.tone);
    const name = nameIn(locale, label.nameAr, label.nameEn);
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
                <LabelMenu label={label} reason={reason} first={first} last={last} toTop={toTop} toBottom={toBottom} open={open} test={`label-actions-${label.id}`} />
            </TableCell>
        </TableRow>
    );
}

/** A label's ⋯ menu: the row's, and the same on its phone card under its own test name. */
function LabelMenu({
    label,
    reason,
    first,
    last,
    toTop,
    toBottom,
    open,
    test,
}: {
    label: LabelData;
    reason: string | undefined;
    first: boolean;
    last: boolean;
    toTop: () => void;
    toBottom: () => void;
    open: (action: 'edit' | 'delete', trigger: HTMLElement | null) => void;
    test: string;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const name = nameIn(locale, label.nameAr, label.nameEn);
    const deleteReason = label.products > 0 ? t('catalog::admin_labels.reason.in_use') : null;

    return reason !== undefined ? (
        <MoreButtonOff name={name} reason={reason} />
    ) : (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} busy={busy} data-test={test} />
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
                        {deleteReason !== null ? <span className="text-copy-13 text-ink-muted">{deleteReason}</span> : null}
                    </span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
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
