import { lazy, Suspense, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { MoreButton } from '@/components/RowMenu';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CarrierPage, PriceGroupData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { C, CarrierLogo, CarrierSwitch, DeleteCarrierDialog, LIST, trackingExample, useCarrierName, useOtherName } from './parts';
import { AmountText, FreeShippingCard, P, PlaceChips } from './prices';

const CarrierDialog = lazy(() => import('./CarrierDialog').then((module) => ({ default: module.CarrierDialog })));
// The price dialog - its place picker, its levels read as they open - loads when it is first opened (§5).
const PriceDialog = lazy(() => import('./PriceDialog').then((module) => ({ default: module.PriceDialog })));

/*
| C3 · A carrier (shipping.md §4.1 parts 1 and 2): the trail back to Carriers, its logo, names, country and
| switch, Edit and Delete for whoever may manage (Q12), and its details; then its Prices, cheapest first,
| and its Free Shipping (R1, R11), changed by whoever may manage prices (R14).
*/

export default function Show(page: CarrierPage) {
    const { carrier, countryName, mayManage, storeCode } = page;
    const t = useTranslator();
    const nameOf = useCarrierName();
    const otherOf = useOtherName();
    const [dialog, setDialog] = useState<'edit' | 'delete' | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const name = nameOf(carrier);
    const other = otherOf(carrier);
    const example = carrier.trackingPattern === null ? null : trackingExample(carrier.trackingPattern);
    const list = `${LIST}?store=${encodeURIComponent(storeCode)}`;
    const close = (open: boolean) => (open ? undefined : setDialog(null));

    return (
        <AdminLayout
            title={name}
            subtitle={countryName}
            breadcrumbs={[{ label: t(`${C}.title`), href: list }]}
            action={
                mayManage ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <ActionButton
                            type="button"
                            variant="outline"
                            onClick={(event) => {
                                opener.current = event.currentTarget;
                                setDialog('edit');
                            }}
                            data-test="edit-carrier"
                        >
                            {t(`${C}.edit`)}
                        </ActionButton>
                        <ActionButton
                            type="button"
                            variant="outline"
                            className="text-destructive"
                            disabledReason={carrier.active ? t(`${C}.delete_reason_on`) : undefined}
                            onClick={(event) => {
                                opener.current = event.currentTarget;
                                setDialog('delete');
                            }}
                            data-test="delete-carrier"
                        >
                            {t(`${C}.delete`)}
                        </ActionButton>
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-4">
                <FormError />

                <Card className="material-base">
                    <CardHeader className="flex flex-row flex-wrap items-center gap-3">
                        <CarrierLogo carrier={carrier} name={name} size="size-12" />
                        <div className="grid min-w-0 flex-1 gap-0.5">
                            <CardTitle className="flex flex-wrap items-center gap-2 text-heading-16 text-ink">
                                {name}
                                {carrier.active ? null : (
                                    <Badge className={tone('gray-subtle')} data-test="carrier-off">
                                        {t(`${C}.off`)}
                                    </Badge>
                                )}
                            </CardTitle>
                            {other !== '' && other !== name ? <span className="text-copy-13 text-ink-muted">{other}</span> : null}
                        </div>
                        {mayManage ? (
                            <CarrierSwitch carrier={carrier} name={name} storeCode={storeCode} />
                        ) : (
                            // Read-only (Q12): its state in words, on as well as off.
                            <span className="text-copy-14" data-test="carrier-state">
                                {carrier.active ? t(`${C}.column.on`) : t(`${C}.off`)}
                            </span>
                        )}
                    </CardHeader>
                    <CardContent>
                        <h2 className="sr-only">{t(`${C}.page.details`)}</h2>
                        <dl className="grid gap-3 text-copy-14 sm:grid-cols-[max-content_1fr] sm:gap-x-6">
                            <dt className="text-ink-muted">{t(`${C}.page.phone`)}</dt>
                            <dd data-test="carrier-phone">
                                {carrier.phone !== null ? (
                                    <bdi dir="ltr" className="tw-figure">
                                        {carrier.phone}
                                    </bdi>
                                ) : (
                                    t(`${C}.page.none`)
                                )}
                            </dd>
                            <dt className="text-ink-muted">{t(`${C}.page.website`)}</dt>
                            <dd className="min-w-0 break-all" data-test="carrier-website">
                                {carrier.website !== null ? (
                                    <a href={carrier.website} target="_blank" rel="noreferrer" dir="ltr" className="text-ink underline-offset-4 hover:underline">
                                        {carrier.website}
                                    </a>
                                ) : (
                                    t(`${C}.page.none`)
                                )}
                            </dd>
                            <dt className="text-ink-muted">{t(`${C}.page.tracking`)}</dt>
                            <dd className="min-w-0 break-all" data-test="carrier-tracking">
                                {carrier.trackingPattern !== null ? (
                                    <>
                                        <bdi dir="ltr">{carrier.trackingPattern}</bdi>
                                        {example !== null ? <span className="block text-ink-muted">{t(`${C}.tracking.example`, { url: example })}</span> : null}
                                    </>
                                ) : (
                                    t(`${C}.page.none`)
                                )}
                            </dd>
                        </dl>
                    </CardContent>
                </Card>

                <PricesCard page={page} carrierName={name} />
                <FreeShippingCard carrier={carrier} storeCode={storeCode} storeFreeAbove={page.storeFreeAbove} mayManage={page.mayManagePrices} currency={page.currency} decimals={page.decimals} />
            </div>

            {dialog === 'edit' ? (
                <Suspense fallback={null}>
                    <CarrierDialog carrier={carrier} countryCode={carrier.countryCode} countryName={countryName} storeCode={storeCode} open onOpenChange={close} returnFocusTo={opener} />
                </Suspense>
            ) : null}
            {dialog === 'delete' ? <DeleteCarrierDialog carrier={carrier} name={name} storeCode={storeCode} fromPage open onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

type PriceAction = { action: 'add' } | { action: 'edit' | 'delete'; group: PriceGroupData } | null;

/** The carrier's prices (R1): each its amount and its places as chips (R9), Edit and Delete for a price manager. */
function PricesCard({ page, carrierName }: { page: CarrierPage; carrierName: string }) {
    const t = useTranslator();
    const [action, setAction] = useState<PriceAction>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setAction(null));
    const add = (trigger: HTMLElement) => {
        opener.current = trigger;
        setAction({ action: 'add' });
    };

    return (
        <Card className="material-base" data-test="prices">
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <CardTitle className="text-heading-16 text-ink">{t(`${P}.title`)}</CardTitle>
                {page.mayManagePrices && page.prices.length > 0 ? (
                    <ActionButton type="button" variant="outline" size="sm" onClick={(event) => add(event.currentTarget)} data-test="add-price">
                        {t(`${P}.add`)}
                    </ActionButton>
                ) : null}
            </CardHeader>
            <CardContent>
                {page.prices.length === 0 ? (
                    <Empty className="border border-dashed border-line" data-test="prices-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${P}.empty`)}</EmptyDescription>
                        </EmptyHeader>
                        {page.mayManagePrices ? (
                            <EmptyContent>
                                <ActionButton type="button" onClick={(event) => add(event.currentTarget)} data-test="add-price">
                                    {t(`${P}.add`)}
                                </ActionButton>
                            </EmptyContent>
                        ) : null}
                    </Empty>
                ) : (
                    <ul className="divide-y divide-line">
                        {page.prices.map((group) => (
                            <PriceRow
                                key={group.id}
                                group={group}
                                mayManage={page.mayManagePrices}
                                open={(next, trigger) => {
                                    opener.current = trigger;
                                    setAction({ action: next, group });
                                }}
                            />
                        ))}
                    </ul>
                )}
            </CardContent>

            {action !== null && action.action !== 'delete' ? (
                <Suspense fallback={null}>
                    <PriceDialog
                        // A new dialog for another price: what was ticked for one never carries to the next.
                        key={action.action === 'add' ? 'add' : action.group.id}
                        carrierId={page.carrier.id}
                        carrierName={carrierName}
                        storeCode={page.storeCode}
                        countryName={page.countryName}
                        group={action.action === 'add' ? null : action.group}
                        groups={page.prices}
                        version={page.pricesVersion}
                        currency={page.currency}
                        decimals={page.decimals}
                        open
                        onOpenChange={close}
                        onOpenGroup={(id) => {
                            const found = page.prices.find((each) => each.id === id);

                            if (found !== undefined) {
                                setAction({ action: 'edit', group: found });
                            }
                        }}
                        returnFocusTo={opener}
                    />
                </Suspense>
            ) : null}
            {action !== null && action.action === 'delete' ? (
                <DeletePriceDialog group={action.group} carrierId={page.carrier.id} carrierName={carrierName} storeCode={page.storeCode} version={page.pricesVersion} onOpenChange={close} returnFocusTo={opener} />
            ) : null}
        </Card>
    );
}

function PriceRow({ group, mayManage, open }: { group: PriceGroupData; mayManage: boolean; open: (action: 'edit' | 'delete', trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const more = useRef<HTMLButtonElement>(null);

    return (
        <li className="grid gap-2 py-3 sm:grid-cols-[8rem_1fr_auto] sm:items-start" data-test={`price-${group.id}`}>
            <span className="text-label-14 text-ink" data-test="price-amount">
                <AmountText amount={group.amount} />
            </span>
            <PlaceChips places={group.places} />
            {mayManage ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <MoreButton ref={more} name={t(`${P}.actions`, { amount: group.amount.text })} data-test={`price-actions-${group.id}`} />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-48">
                        <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-price">
                            {t(`${P}.edit`)}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" onSelect={() => open('delete', more.current)} data-test="delete-price">
                            {t(`${P}.delete`)}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        </li>
    );
}

/** Delete Price (R8): what follows, no typed name - a price is quickly made again. */
function DeletePriceDialog({
    group,
    carrierId,
    carrierName,
    storeCode,
    version,
    onOpenChange,
    returnFocusTo,
}: {
    group: PriceGroupData;
    carrierId: string;
    carrierName: string;
    storeCode: string;
    version: string;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${P}.delete_title`, { amount: group.amount.minor === 0 ? t(`${P}.free`) : group.amount.text })}
            description={t(`${P}.delete_body`, { count: figure(locale, group.places.length), carrier: carrierName })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        router.post(
                            `${LIST}/${carrierId}/prices/${group.id}/delete`,
                            { store: storeCode, version },
                            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) },
                        )
                    }
                    data-test="confirm-delete-price"
                >
                    {t(`${P}.delete_confirm`)}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
