import { lazy, Suspense, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { StoreFilter } from '@/components/StoreFilter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { DragHandle, MoveItems, SortableGroup, SortableGroups, useSavedOrder, useSortableRow } from '@/pages/Catalog/Admin/SortableList';
import type { BoxData, BoxesPage, PackingSizeData, PackingSizesData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { B, BOXES, inside, kgOf } from './parts';
import { SizeLine } from './SizeLine';

// The dialogs - the box's form and checks, the sizes' search - load when first opened (frontend.md §5).
const BoxDialog = lazy(() => import('./BoxDialog').then((module) => ({ default: module.BoxDialog })));
const SizesDialog = lazy(() => import('./SizesDialog').then((module) => ({ default: module.SizesDialog })));

/*
| X1 · Shipping › Boxes (shipping.md §4.1 part 3; the owner, 2026-10-31, (a) in all fourteen): one page, top to
| bottom (B2) - a note on what boxes do (B14), the padding (B10), the boxes in their order with drag and Move to
| Top / Bottom (B9), the switch saved at once (B7) and Delete through a plain dialog (B8), each box's room after
| the padding (B6); then the sizes packed by hand, filtered, 25 at a time (B12), and Add Sizes (B11).
*/

type Dialog = { action: 'add' } | { action: 'edit' | 'delete'; box: BoxData } | { action: 'sizes' } | null;

export default function Index(page: BoxesPage) {
    const t = useTranslator();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const open = (next: Dialog, trigger: HTMLElement | null) => {
        opener.current = trigger;
        setDialog(next);
    };

    return (
        <AdminLayout title={t(`${B}.title`)} subtitle={t(`${B}.subtitle`)}>
            <div className="grid gap-6">
                <StoreFilter stores={page.stores} value={page.storeCode} />
                <FormError />
                <Note data-test="boxes-note">{t(`${B}.note`)}</Note>
                <PaddingCard storeCode={page.storeCode} paddingMm={page.paddingMm} />
                <BoxesSection page={page} open={open} />
                <HandPacked page={page} onAdd={(trigger) => open({ action: 'sizes' }, trigger)} />
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit') ? (
                <Suspense fallback={null}>
                    <BoxDialog box={dialog.action === 'edit' ? dialog.box : null} storeCode={page.storeCode} paddingMm={page.paddingMm} open onOpenChange={close} returnFocusTo={opener} />
                </Suspense>
            ) : null}
            {dialog !== null && dialog.action === 'sizes' ? (
                <Suspense fallback={null}>
                    <SizesDialog storeCode={page.storeCode} onOpenChange={close} />
                </Suspense>
            ) : null}
            {dialog !== null && dialog.action === 'delete' ? <DeleteBoxDialog box={dialog.box} storeCode={page.storeCode} onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

/** The padding (B10): Platform's store setting, saved through Platform's own settings route; 0–100 whole mm. */
function PaddingCard({ storeCode, paddingMm }: { storeCode: string; paddingMm: number }) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props;
    const [typed, setTyped] = useState(String(paddingMm));
    const [busy, setBusy] = useState(false);
    const valid = /^\d{1,3}$/.test(typed.trim()) && Number(typed.trim()) <= 100;

    return (
        <Card className="material-base" data-test="padding">
            <CardHeader>
                <CardTitle className="text-heading-16 text-ink">{t(`${B}.padding.title`)}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-2">
                <p className="text-copy-14" data-test="padding-state">
                    {t(`${B}.padding.saved`, { mm: figure('en', paddingMm) })}
                </p>
                <div className="flex flex-wrap items-end gap-2">
                    <div className="grid gap-1">
                        <label htmlFor="padding-mm" className="text-label-14 text-ink">
                            {`${t(`${B}.padding.label`)} (${t(`${B}.padding.unit`)})`}
                        </label>
                        <Input
                            id="padding-mm"
                            dir="ltr"
                            inputMode="numeric"
                            className="tw-figure w-28"
                            value={typed}
                            onChange={(event) => setTyped(toLatinDigits(event.target.value))}
                            aria-invalid={!valid || errors.value !== undefined ? true : undefined}
                            aria-describedby="padding-mm-hint"
                            data-test="padding-mm"
                        />
                    </div>
                    <ActionButton
                        type="button"
                        loading={busy}
                        disabledReason={!valid ? t(`${B}.padding.invalid`) : undefined}
                        onClick={() =>
                            router.post(`/admin/settings/shipping.boxes.padding_mm`, { store: storeCode, value: typed.trim() }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                        }
                        data-test="save-padding"
                    >
                        {t(`${B}.padding.save`)}
                    </ActionButton>
                </div>
                <p id="padding-mm-hint" className="text-copy-13 text-ink-muted">
                    {t(`${B}.padding.hint`)}
                </p>
                {!valid ? <FieldError className="text-copy-13">{t(`${B}.padding.invalid`)}</FieldError> : errors.value ? <FieldError className="text-copy-13">{errors.value}</FieldError> : null}
            </CardContent>
        </Card>
    );
}

function BoxesSection({ page, open }: { page: BoxesPage; open: (dialog: Dialog, trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const { order, move, toTop, toBottom } = useSavedOrder(
        page.boxes.map((box) => box.id),
        `${BOXES}/order?store=${encodeURIComponent(page.storeCode)}`,
    );
    const byId = new Map(page.boxes.map((box) => [box.id, box]));
    const rows = order.flatMap((id) => byId.get(id) ?? []);
    const add = (trigger: HTMLElement) => open({ action: 'add' }, trigger);

    return (
        <section aria-labelledby="boxes-title" className="grid gap-3" data-test="boxes">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="boxes-title" className="text-heading-16 text-ink">
                    {t(`${B}.list.title`)}
                </h2>
                {rows.length > 0 ? (
                    <ActionButton type="button" variant="outline" size="sm" onClick={(event) => add(event.currentTarget)} data-test="add-box">
                        {t(`${B}.list.add`)}
                    </ActionButton>
                ) : null}
            </div>

            {rows.length === 0 ? (
                <Empty className="material-base" data-test="boxes-empty">
                    <EmptyHeader>
                        <EmptyTitle className="text-heading-16 text-ink">{t(`${B}.list.empty_title`)}</EmptyTitle>
                        <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${B}.list.empty_body`)}</EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>
                        <ActionButton type="button" onClick={(event) => add(event.currentTarget)} data-test="add-box">
                            {t(`${B}.list.add`)}
                        </ActionButton>
                    </EmptyContent>
                </Empty>
            ) : (
                <>
                    <p className="text-copy-13 text-ink-muted">{t(`${B}.list.order_hint`)}</p>
                    {/* A phone: a card each, ordered from the ⋯ menu - no table to scroll. */}
                    <ul className="grid gap-3 sm:hidden">
                        {rows.map((box, index) => (
                            <li key={box.id} className={`material-base grid gap-2 p-4 ${box.active ? '' : 'text-ink-muted'}`} data-test={`box-card-${box.id}`}>
                                <div className="flex items-start justify-between gap-2">
                                    <BoxName box={box} />
                                    <BoxActions box={box} first={index === 0} last={index === rows.length - 1} toTop={() => toTop(box.id)} toBottom={() => toBottom(box.id)} open={open} test={`box-card-actions-${box.id}`} />
                                </div>
                                <BoxSize box={box} paddingMm={page.paddingMm} />
                                <div className="flex items-center justify-between gap-2 text-copy-14">
                                    <span className="tw-figure">{t(`${B}.list.weight`, { kg: kgOf(box.maxWeightG) })}</span>
                                    <BoxSwitch box={box} storeCode={page.storeCode} test={`box-card-switch-${box.id}`} />
                                </div>
                            </li>
                        ))}
                    </ul>
                    <SortableGroups groups={{ boxes: order }} labelOf={(id) => byId.get(id)?.name ?? id} onMove={(_, ids) => move(ids)}>
                        <div className="material-base hidden sm:block">
                            <Table>
                                <TableCaption className="sr-only">{t(`${B}.list.title`)}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t(`${B}.list.column.order`)}</span>
                                        </TableHead>
                                        <TableHead>{t(`${B}.list.column.name`)}</TableHead>
                                        <TableHead>{t(`${B}.list.column.size`)}</TableHead>
                                        <TableHead className="text-end">{t(`${B}.list.column.weight`)}</TableHead>
                                        <TableHead className="w-16">{t(`${B}.list.column.on`)}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t(`${B}.list.column.actions`)}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <SortableGroup id="boxes" ids={order}>
                                        {rows.map((box, index) => (
                                            <BoxRow
                                                key={box.id}
                                                box={box}
                                                page={page}
                                                first={index === 0}
                                                last={index === rows.length - 1}
                                                toTop={() => toTop(box.id)}
                                                toBottom={() => toBottom(box.id)}
                                                open={open}
                                            />
                                        ))}
                                    </SortableGroup>
                                </TableBody>
                            </Table>
                        </div>
                    </SortableGroups>
                </>
            )}
        </section>
    );
}

type RowProps = { box: BoxData; first: boolean; last: boolean; toTop: () => void; toBottom: () => void; open: (dialog: Dialog, trigger: HTMLElement | null) => void };

function BoxRow({ box, page, first, last, toTop, toBottom, open }: RowProps & { page: BoxesPage }) {
    const t = useTranslator();
    const row = useSortableRow(box.id);

    return (
        <TableRow
            ref={row.ref}
            style={row.style}
            data-dragging={row.dragging || undefined}
            className={`relative z-0 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80 ${box.active ? '' : 'text-ink-muted'}`}
            data-test={`box-${box.id}`}
        >
            <TableCell>
                <DragHandle handle={row.handle} label={box.name} test={`drag-box-${box.id}`} />
            </TableCell>
            <TableCell>
                <BoxName box={box} />
            </TableCell>
            <TableCell>
                <BoxSize box={box} paddingMm={page.paddingMm} />
            </TableCell>
            <TableCell className="tw-figure text-end">{t(`${B}.list.weight`, { kg: kgOf(box.maxWeightG) })}</TableCell>
            <TableCell>
                <BoxSwitch box={box} storeCode={page.storeCode} test={`box-switch-${box.id}`} />
            </TableCell>
            <TableCell className="text-end">
                <BoxActions box={box} first={first} last={last} toTop={toTop} toBottom={toBottom} open={open} test={`box-actions-${box.id}`} />
            </TableCell>
        </TableRow>
    );
}

function BoxName({ box }: { box: BoxData }) {
    const t = useTranslator();

    return (
        <span className="flex flex-wrap items-center gap-2 text-label-14 text-ink" data-test="box-name">
            {box.name}
            {box.active ? null : (
                <Badge className={tone('gray-subtle')} data-test="box-off">
                    {t(`${B}.list.off`)}
                </Badge>
            )}
        </span>
    );
}

/** The inner size, and under it what fits after the padding (B6). */
function BoxSize({ box, paddingMm }: { box: BoxData; paddingMm: number }) {
    const t = useTranslator();
    const room = inside([box.lengthMm, box.widthMm, box.heightMm], paddingMm);

    return (
        <span className="grid gap-0.5 text-copy-14">
            <bdi dir="ltr" className="tw-figure">
                {t(`${B}.list.size`, { l: box.lengthMm, w: box.widthMm, h: box.heightMm })}
            </bdi>
            <span className="text-copy-12 text-ink-muted" data-test="box-room">
                {room === null ? t(`${B}.list.too_small`) : t(`${B}.list.inside`, { l: room[0], w: room[1], h: room[2] })}
            </span>
        </span>
    );
}

/** The switch, saved at once: an off box only leaves the suggestion (B7). */
function BoxSwitch({ box, storeCode, test }: { box: BoxData; storeCode: string; test: string }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <Switch
            checked={box.active}
            disabled={busy}
            onCheckedChange={(on) => router.post(`${BOXES}/${box.id}/switch`, { store: storeCode, active: on }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
            aria-label={t(`${B}.list.switch`, { name: box.name })}
            className="data-[state=unchecked]:bg-ink-subtle"
            data-test={test}
        />
    );
}

function BoxActions({ box, first, last, toTop, toBottom, open, test }: RowProps & { test: string }) {
    const t = useTranslator();
    const more = useRef<HTMLButtonElement>(null);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={box.name} data-test={test} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                <DropdownMenuItem onSelect={() => open({ action: 'edit', box }, more.current)} data-test="edit-box">
                    {t(`${B}.list.edit`)}
                </DropdownMenuItem>
                <MoveItems first={first} last={last} onTop={toTop} onBottom={toBottom} />
                <DropdownMenuSeparator />
                <Guarded destructive label={t(`${B}.list.delete`)} onSelect={() => open({ action: 'delete', box }, more.current)} test="delete-box" />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Delete, on or off (B8): it leaves the suggestion; no typed name - nothing else names a box. */
function DeleteBoxDialog({ box, storeCode, onOpenChange, returnFocusTo }: { box: BoxData; storeCode: string; onOpenChange: (open: boolean) => void; returnFocusTo: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${B}.delete_title`, { name: box.name })}
            description={t(`${B}.delete_body`)}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`${BOXES}/${box.id}/delete`, { store: storeCode }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-box"
                >
                    {t(`${B}.delete_confirm`)}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}

/** The sizes packed by hand (B12): filtered, 25 at a time with Load More; Pack Automatically takes one off. */
function HandPacked({ page, onAdd }: { page: BoxesPage; onAdd: (trigger: HTMLElement) => void }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const [filter, setFilter] = useState(page.filter ?? '');
    const [extra, setExtra] = useState<{ for: PackingSizesData; rows: PackingSizeData[]; next: string | null } | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    // More pages belong to the first page they followed: a new page from the server starts the list again.
    const more = extra !== null && extra.for === page.handPacked ? extra : null;
    const rows = [...page.handPacked.rows, ...(more?.rows ?? [])];
    const next = more !== null ? more.next : page.handPacked.next;

    const search = (text: string) =>
        router.get(BOXES, { store: page.storeCode, ...(text.trim().length >= 2 ? { filter: text.trim() } : {}) }, { preserveState: true, preserveScroll: true, replace: true, only: ['handPacked', 'filter'] });

    function loadMore() {
        if (next === null) {
            return;
        }

        fetch(`${BOXES}/sizes?${new URLSearchParams({ store: page.storeCode, hand: '1', after: next, ...(page.filter !== null ? { q: page.filter } : {}) }).toString()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? (response.json() as Promise<PackingSizesData>) : Promise.reject(new Error(String(response.status)))))
            .then((found) => setExtra({ for: page.handPacked, rows: [...(more?.rows ?? []), ...found.rows], next: found.next }))
            .catch(() => undefined);
    }

    return (
        <section aria-labelledby="hand-title" className="grid gap-3" data-test="hand-packed">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="hand-title" className="text-heading-16 text-ink">
                    {t(`${B}.hand.title`)}
                </h2>
                <ActionButton type="button" variant="outline" size="sm" onClick={(event) => onAdd(event.currentTarget)} data-test="add-sizes">
                    {t(`${B}.hand.add`)}
                </ActionButton>
            </div>
            <p className="text-copy-13 text-ink-muted">{t(`${B}.hand.body`)}</p>
            {page.handPacked.total > 0 || page.filter !== null ? (
                <Input
                    value={filter}
                    onChange={(event) => {
                        setFilter(event.target.value);
                        search(event.target.value);
                    }}
                    placeholder={t(`${B}.hand.filter`)}
                    aria-label={t(`${B}.hand.filter_label`)}
                    className="max-w-64"
                    data-test="hand-filter"
                />
            ) : null}

            {rows.length === 0 ? (
                <p className="text-copy-14 text-ink-muted" data-test="hand-empty">
                    {page.filter !== null ? t(`${B}.hand.none_match`, { query: page.filter }) : t(`${B}.hand.empty`)}
                </p>
            ) : (
                <ul className="material-base divide-y divide-line">
                    {rows.map((size) => (
                        <li key={size.variantId} className="flex flex-wrap items-center gap-3 px-4 py-3" data-test={`hand-${size.variantId}`}>
                            <SizeLine size={size} />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={busy === size.variantId}
                                aria-label={t(`${B}.hand.automatic_label`, { name: `${size.productName} ${size.code}` })}
                                onClick={() =>
                                    router.post(
                                        `${BOXES}/sizes`,
                                        { store: page.storeCode, variants: [size.variantId], by_hand: false },
                                        { preserveScroll: true, onStart: () => setBusy(size.variantId), onFinish: () => setBusy(null) },
                                    )
                                }
                                data-test={`pack-automatically-${size.variantId}`}
                            >
                                {t(`${B}.hand.automatic`)}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {rows.length > 0 ? (
                <div className="flex flex-wrap items-center gap-3 text-copy-13 text-ink-muted">
                    <span>{t(`${B}.hand.count`, { shown: figure(locale, rows.length), total: figure(locale, page.handPacked.total) })}</span>
                    {next !== null ? (
                        <Button type="button" variant="outline" size="sm" onClick={loadMore} data-test="hand-more">
                            {t(`${B}.hand.more`)}
                        </Button>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
