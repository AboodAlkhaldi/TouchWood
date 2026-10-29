import { lazy, Suspense, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { StoreFilter } from '@/components/StoreFilter';
import { Badge } from '@/components/ui/badge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { DragHandle, MoveItems, SortableGroup, SortableGroups, useSavedOrder, useSortableRow } from '@/pages/Catalog/Admin/SortableList';
import type { CarrierCountryData, CarrierData, CarriersPage } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import { C, CarrierLogo, CarrierSwitch, DeleteCarrierDialog, LIST, useCarrierName, useOtherName } from './parts';

// The add and edit dialog - its form, its checks as typed, the logo box - loads when it is first opened,
// not with the page, which the drag handles' code shares (frontend.md §5, the page budget).
const CarrierDialog = lazy(() => import('./CarrierDialog').then((module) => ({ default: module.CarrierDialog })));

/*
| C1 · Shipping › Carriers (shipping.md §4.1 part 1; the owner, 2026-10-29, (a) in all sixteen): a section
| per country the store ships to, its own first (Q2), each with its carriers in their order and its own
| Add Carrier (Q3). The order is dragged by each row's handle within its country and saved when dropped, or
| a carrier moved to the top or the bottom from its ⋯ menu (Q7). The switch asks before switching off (Q5);
| Delete is out of reach while the carrier is on (Q6). A country with no carrier says what that means for
| its orders (Q11). Whoever may only view sees none of the buttons (Q12).
*/

type Dialog = { action: 'add'; country: CarrierCountryData } | { action: 'edit' | 'delete'; carrier: CarrierData; country: CarrierCountryData } | null;

export default function Index(page: CarriersPage) {
    const t = useTranslator();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));

    return (
        <AdminLayout title={t(`${C}.title`)} subtitle={t(`${C}.subtitle`)}>
            <div className="grid gap-6">
                <StoreFilter stores={page.stores} value={page.storeCode} />
                <FormError />

                {page.countries.map((country) => (
                    <CountrySection
                        key={country.code}
                        country={country}
                        page={page}
                        open={(next, trigger) => {
                            opener.current = trigger;
                            setDialog(next);
                        }}
                    />
                ))}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit') ? (
                <Suspense fallback={null}>
                    <CarrierDialog
                        carrier={dialog.action === 'edit' ? dialog.carrier : null}
                        countryCode={dialog.country.code}
                        countryName={dialog.country.name}
                        storeCode={page.storeCode}
                        open
                        onOpenChange={close}
                        returnFocusTo={opener}
                    />
                </Suspense>
            ) : null}
            {dialog !== null && dialog.action === 'delete' ? <DeleteFor carrier={dialog.carrier} storeCode={page.storeCode} onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function DeleteFor({ carrier, storeCode, onOpenChange, returnFocusTo }: { carrier: CarrierData; storeCode: string; onOpenChange: (open: boolean) => void; returnFocusTo: React.RefObject<HTMLElement | null> }) {
    const nameOf = useCarrierName();

    return <DeleteCarrierDialog carrier={carrier} name={nameOf(carrier)} storeCode={storeCode} open onOpenChange={onOpenChange} returnFocusTo={returnFocusTo} />;
}

/** One ship-to country: its heading, Add Carrier, and its carriers - or what having none means (Q11). */
function CountrySection({ country, page, open }: { country: CarrierCountryData; page: CarriersPage; open: (dialog: Dialog, trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const nameOf = useCarrierName();
    const heading = `carriers-${country.code}-title`;
    // One country's order, saved as that country's (OrderCarriers): the store and the country ride in the address.
    const { order, move, toTop, toBottom } = useSavedOrder(
        country.carriers.map((carrier) => carrier.id),
        `${LIST}/order?store=${encodeURIComponent(page.storeCode)}&country=${country.code}`,
    );
    const byId = new Map(country.carriers.map((carrier) => [carrier.id, carrier]));
    const rows = order.flatMap((id) => byId.get(id) ?? []);
    const add = (trigger: HTMLElement | null) => open({ action: 'add', country }, trigger);

    return (
        <section aria-labelledby={heading} className="grid gap-3" data-test={`carriers-${country.code}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id={heading} className="flex items-center gap-2 text-heading-16 text-ink">
                    {country.name}
                    {country.own ? (
                        <Badge className={tone('blue-subtle')} data-test="country-own">
                            {t(`${C}.own`)}
                        </Badge>
                    ) : null}
                </h2>
                {page.mayManage && rows.length > 0 ? (
                    <ActionButton type="button" variant="outline" size="sm" onClick={(event) => add(event.currentTarget)} data-test={`add-carrier-${country.code}`}>
                        {t(`${C}.add`)}
                    </ActionButton>
                ) : null}
            </div>

            {rows.length === 0 ? (
                <Empty className="material-base" data-test={`carriers-empty-${country.code}`}>
                    <EmptyHeader>
                        <EmptyTitle className="text-heading-16 text-ink">{t(`${C}.empty.title`, { country: country.name })}</EmptyTitle>
                        <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${C}.empty.body`, { country: country.name })}</EmptyDescription>
                    </EmptyHeader>
                    {page.mayManage ? (
                        <EmptyContent>
                            <ActionButton type="button" onClick={(event) => add(event.currentTarget)} data-test={`add-carrier-${country.code}`}>
                                {t(`${C}.add`)}
                            </ActionButton>
                        </EmptyContent>
                    ) : null}
                </Empty>
            ) : (
                <SortableGroups groups={{ [country.code]: order }} labelOf={(id) => (byId.has(id) ? nameOf(byId.get(id) as CarrierData) : id)} onMove={(_, ids) => move(ids)}>
                    <div className="material-base">
                        <Table>
                            <TableCaption className="sr-only">{country.name}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    {page.mayManage ? (
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t(`${C}.column.order`)}</span>
                                        </TableHead>
                                    ) : null}
                                    <TableHead>{t(`${C}.column.name`)}</TableHead>
                                    <TableHead className="hidden md:table-cell">{t(`${C}.column.contact`)}</TableHead>
                                    <TableHead className="hidden sm:table-cell">{t(`${C}.column.tracking`)}</TableHead>
                                    <TableHead className="w-16">{t(`${C}.column.on`)}</TableHead>
                                    {page.mayManage ? (
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t(`${C}.column.actions`)}</span>
                                        </TableHead>
                                    ) : null}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <SortableGroup id={country.code} ids={order}>
                                    {rows.map((carrier, index) => (
                                        <Row
                                            key={carrier.id}
                                            carrier={carrier}
                                            page={page}
                                            first={index === 0}
                                            last={index === rows.length - 1}
                                            toTop={() => toTop(carrier.id)}
                                            toBottom={() => toBottom(carrier.id)}
                                            open={(action, trigger) => open({ action, carrier, country }, trigger)}
                                        />
                                    ))}
                                </SortableGroup>
                            </TableBody>
                        </Table>
                    </div>
                </SortableGroups>
            )}
        </section>
    );
}

function Row({
    carrier,
    page,
    first,
    last,
    toTop,
    toBottom,
    open,
}: {
    carrier: CarrierData;
    page: CarriersPage;
    first: boolean;
    last: boolean;
    toTop: () => void;
    toBottom: () => void;
    open: (action: 'edit' | 'delete', trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const nameOf = useCarrierName();
    const otherOf = useOtherName();
    const more = useRef<HTMLButtonElement>(null);
    const name = nameOf(carrier);
    const other = otherOf(carrier);
    const row = useSortableRow(carrier.id, !page.mayManage);

    return (
        <TableRow
            ref={row.ref}
            style={row.style}
            data-dragging={row.dragging || undefined}
            className={`relative z-0 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80 ${carrier.active ? '' : 'text-ink-muted'}`}
            data-test={`carrier-${carrier.id}`}
        >
            {page.mayManage ? (
                <TableCell>
                    <DragHandle handle={row.handle} label={name} test={`drag-carrier-${carrier.id}`} />
                </TableCell>
            ) : null}
            <TableCell>
                <div className="flex min-w-0 items-center gap-3">
                    <CarrierLogo carrier={carrier} name={name} />
                    <div className="grid min-w-0 gap-0.5">
                        <span className="flex flex-wrap items-center gap-2">
                            <Link href={`${LIST}/${carrier.id}?store=${encodeURIComponent(page.storeCode)}`} className="truncate text-label-14 text-ink underline-offset-4 hover:underline" data-test="carrier-name">
                                {name}
                            </Link>
                            {carrier.active ? null : (
                                <Badge className={tone('gray-subtle')} data-test="carrier-off">
                                    {t(`${C}.off`)}
                                </Badge>
                            )}
                        </span>
                        {other !== '' && other !== name ? <span className="truncate text-copy-13 text-ink-muted">{other}</span> : null}
                    </div>
                </div>
            </TableCell>
            <TableCell className="hidden md:table-cell">
                <Contact carrier={carrier} />
            </TableCell>
            <TableCell className="hidden text-copy-14 sm:table-cell">{carrier.trackingPattern !== null ? t(`${C}.tracking_yes`) : t(`${C}.tracking_no`)}</TableCell>
            <TableCell>
                {page.mayManage ? (
                    <CarrierSwitch carrier={carrier} name={name} storeCode={page.storeCode} />
                ) : (
                    <span className="text-copy-14">{carrier.active ? t(`${C}.column.on`) : t(`${C}.off`)}</span>
                )}
            </TableCell>
            {page.mayManage ? (
                <TableCell className="text-end">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={name} data-test={`carrier-actions-${carrier.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-carrier">
                                {t(`${C}.edit`)}
                            </DropdownMenuItem>
                            <MoveItems first={first} last={last} onTop={toTop} onBottom={toBottom} />
                            {/* Destructive items last, after a divider (Geist's Menu). */}
                            <DropdownMenuSeparator />
                            <Guarded destructive label={t(`${C}.delete`)} reason={carrier.active ? t(`${C}.delete_reason_on`) : null} onSelect={() => open('delete', more.current)} test="delete-carrier" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </TableCell>
            ) : null}
        </TableRow>
    );
}

/** Its phone and website, each only when it has one. */
export function Contact({ carrier }: { carrier: CarrierData }) {
    const t = useTranslator();

    if (carrier.phone === null && carrier.website === null) {
        return <span className="text-copy-14 text-ink-muted">{t(`${C}.no_contact`)}</span>;
    }

    return (
        <div className="grid gap-0.5 text-copy-14">
            {carrier.phone !== null ? (
                <bdi dir="ltr" className="tw-figure">
                    {carrier.phone}
                </bdi>
            ) : null}
            {carrier.website !== null ? (
                <a href={carrier.website} target="_blank" rel="noreferrer" className="truncate text-ink underline-offset-4 hover:underline" dir="ltr">
                    {carrier.website.replace(/^https:\/\//i, '')}
                </a>
            ) : null}
        </div>
    );
}
