import { lazy, Suspense, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { BrandData, BrandsPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { FatesDialog } from '../FatesDialog';
import { MoreButton, MoreButtonOff, NameCells, NameHeads, StateBadge, nameIn, useAllStoresReason, useLocale } from '../parts';
import { DragHandle, MoveItems, SortableGroup, SortableGroups, useSavedOrder, useSortableRow } from '../SortableList';

// The add and edit dialog - its form, its checks as typed, the country picker - loads when it is first
// opened, not with the page, which the drag handles' code shares (frontend.md §5, the page budget).
const BrandDialog = lazy(() => import('./BrandDialog').then((module) => ({ default: module.BrandDialog })));

/*
| The brands screen (catalog.md §1.6, §4.4 S1), on shadcn's parts with Geist's rules (frontend.md
| §1.11): every brand in the list's order with its fixed number, Brand No. (amendment 7(b)) - never
| its place - its agency, whether its products show in search and shop-wide lists or only through its
| own category (a secondary brand, amendment 5(k)), how many products carry it, its state and the
| default mark.
|
| The order is dragged by each row's handle and saved when dropped, or a brand moved to the top or the
| bottom from its ⋯ menu (amendment 16(d)); a new brand goes last, with no place typed.
|
| Every change needs `catalog.brand.manage` with All stores (§3): for anyone else every button stays
| in sight, out of reach, with that reason (P2). Deactivating gives each product its fate (FatesDialog); the default brand is
| never deactivated or deleted, and a brand any product carries is not deleted - their items stay in
| the menu, out of reach, the reason written under them.
*/

type Dialog = { action: 'add' | 'edit' | 'deactivate' | 'delete'; brand: BrandData | null } | null;

export default function Index({ brands, mayChange, reached, reachedFor, countries }: BrandsPage) {
    const t = useTranslator();
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const add = () => setDialog({ action: 'add', brand: null });
    const reason = useAllStoresReason(mayChange);
    const { order, move, toTop, toBottom } = useSavedOrder(
        brands.map((brand) => brand.id),
        '/admin/brands/order',
    );
    const byId = new Map(brands.map((brand) => [brand.id, brand]));
    const rows = order.flatMap((id) => byId.get(id) ?? []);

    return (
        <AdminLayout
            title={t('catalog::admin_brands.title')}
            subtitle={t('catalog::admin_brands.subtitle')}
            action={
                <ActionButton type="button" onClick={add} disabledReason={reason} data-test="add-brand">
                    {t('catalog::admin_brands.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />

                {brands.length === 0 ? (
                    <Empty className="material-base" data-test="brands-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_brands.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_brands.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={add} disabledReason={reason}>
                                {t('catalog::admin_brands.add')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <SortableGroups groups={{ brands: order }} labelOf={(id) => nameIn(locale, byId.get(id)?.nameAr ?? null, byId.get(id)?.nameEn ?? null)} onMove={(_, ids) => move(ids)}>
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('catalog::admin_brands.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t('catalog::admin.order.column')}</span>
                                        </TableHead>
                                        <TableHead className="w-24">{t('catalog::admin_brands.column.number')}</TableHead>
                                        <NameHeads locale={locale} />
                                        <TableHead>{t('catalog::admin_brands.column.agency')}</TableHead>
                                        <TableHead>{t('catalog::admin_brands.column.listings')}</TableHead>
                                        <TableHead className="text-end">{t('catalog::admin.column.products')}</TableHead>
                                        <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <SortableGroup id="brands" ids={order}>
                                        {rows.map((brand, index) => (
                                            <Row
                                                key={brand.id}
                                                brand={brand}
                                                reason={reason}
                                                first={index === 0}
                                                last={index === rows.length - 1}
                                                toTop={() => toTop(brand.id)}
                                                toBottom={() => toBottom(brand.id)}
                                                open={(action, trigger) => {
                                                    opener.current = trigger;
                                                    setDialog({ action, brand });
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

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit') ? (
                <Suspense fallback={null}>
                    <BrandDialog brand={dialog.brand} countries={countries} open onOpenChange={close} returnFocusTo={opener} />
                </Suspense>
            ) : null}
            {dialog !== null && dialog.action === 'deactivate' && dialog.brand !== null ? (
                <FatesDialog
                    kind="brand"
                    target={{ id: dialog.brand.id, name: nameIn(locale, dialog.brand.nameAr, dialog.brand.nameEn) }}
                    reached={reachedFor === dialog.brand.id ? reached : null}
                    moveTargets={brands
                        .filter((brand) => brand.active && brand.id !== dialog.brand?.id)
                        .map((brand) => ({ id: brand.id, name: nameIn(locale, brand.nameAr, brand.nameEn) }))}
                    open
                    onOpenChange={close}
                    returnFocusTo={opener}
                />
            ) : null}
            {dialog !== null && dialog.action === 'delete' && dialog.brand !== null ? (
                <DeleteBrandDialog brand={dialog.brand} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
        </AdminLayout>
    );
}

function Row({
    brand,
    reason,
    first,
    last,
    toTop,
    toBottom,
    open,
}: {
    brand: BrandData;
    reason: string | undefined;
    first: boolean;
    last: boolean;
    toTop: () => void;
    toBottom: () => void;
    open: (action: 'edit' | 'deactivate' | 'delete', trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const name = nameIn(locale, brand.nameAr, brand.nameEn);
    const row = useSortableRow(brand.id, reason !== undefined);
    const post = (path: string) => router.post(`/admin/brands/${brand.id}/${path}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    // Why an item cannot be used, written under it: the default brand stays, a used brand stays.
    const deactivateReason = brand.isDefault ? t('catalog::admin_brands.reason.default') : null;
    const deleteReason = brand.isDefault ? t('catalog::admin_brands.reason.default') : brand.products > 0 ? t('catalog::admin_brands.reason.in_use') : null;

    return (
        <TableRow
            ref={row.ref}
            style={row.style}
            data-dragging={row.dragging || undefined}
            className="relative z-0 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            data-test={`brand-${brand.id}`}
        >
            <TableCell>
                <DragHandle handle={row.handle} label={name} reason={reason} test={`drag-brand-${brand.id}`} />
            </TableCell>
            <TableCell className="tw-figure" data-test="brand-number">
                {figure(locale, brand.number)}
            </TableCell>
            <NameCells
                locale={locale}
                ar={brand.nameAr}
                en={brand.nameEn}
                lead={
                    <>
                        {brand.logo !== null ? <img src={brand.logo} alt="" className="size-8 rounded-md border border-line object-contain" /> : null}
                        {brand.isDefault ? (
                            <Badge className={tone('blue-subtle')} data-test="brand-default">
                                {t('catalog::admin_brands.default')}
                            </Badge>
                        ) : null}
                    </>
                }
            />
            <TableCell>{t(`catalog::admin_brands.agency.${brand.agencyType}`)}</TableCell>
            <TableCell>{brand.showInDefaultListings ? t('catalog::admin_brands.listings.everywhere') : t('catalog::admin_brands.listings.secondary')}</TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, brand.products)}</TableCell>
            <TableCell>
                <StateBadge active={brand.active} />
            </TableCell>
            <TableCell className="text-end">
                {reason !== undefined ? (
                    <MoreButtonOff name={name} reason={reason} />
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={name} busy={busy} data-test={`brand-actions-${brand.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-brand">
                                {t('catalog::admin_brands.edit')}
                            </DropdownMenuItem>
                            {brand.active && !brand.isDefault ? (
                                <DropdownMenuItem disabled={busy} onSelect={() => post('default')} data-test="default-brand">
                                    {t('catalog::admin_brands.make_default')}
                                </DropdownMenuItem>
                            ) : null}
                            <MoveItems first={first} last={last} onTop={toTop} onBottom={toBottom} />
                            {!brand.active ? (
                                <DropdownMenuItem disabled={busy} onSelect={() => post('activate')} data-test="activate-brand">
                                    {t('catalog::admin_brands.activate')}
                                </DropdownMenuItem>
                            ) : null}
                            {/* Destructive items last, after a divider (Geist's Menu). */}
                            <DropdownMenuSeparator />
                            {brand.active ? <Guarded label={t('catalog::admin_brands.deactivate')} reason={deactivateReason} onSelect={() => open('deactivate', more.current)} test="deactivate-brand" /> : null}
                            <Guarded label={t('catalog::admin_brands.delete')} reason={deleteReason} onSelect={() => open('delete', more.current)} test="delete-brand" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </TableCell>
        </TableRow>
    );
}

/** A destructive item, out of reach with its reason written under it when it cannot be done. */
function Guarded({ label, reason, onSelect, test }: { label: string; reason: string | null; onSelect: () => void; test: string }) {
    return (
        <DropdownMenuItem
            variant="destructive"
            aria-disabled={reason !== null || undefined}
            className={reason !== null ? 'cursor-not-allowed' : undefined}
            onSelect={(event) => {
                if (reason !== null) {
                    event.preventDefault();

                    return;
                }

                onSelect();
            }}
            data-test={test}
        >
            <span className="grid gap-0.5">
                <span className={reason !== null ? 'opacity-60' : undefined}>{label}</span>
                {reason !== null ? <span className="text-copy-12 text-ink-muted">{reason}</span> : null}
            </span>
        </DropdownMenuItem>
    );
}

function DeleteBrandDialog({ brand, open, onOpenChange, returnFocusTo }: { brand: BrandData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_brands.delete_title')}
            description={t('catalog::admin_brands.delete_body', { name: nameIn(locale, brand.nameAr, brand.nameEn) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        router.post(`/admin/brands/${brand.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })
                    }
                    data-test="confirm-delete-brand"
                >
                    {t('catalog::admin_brands.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
