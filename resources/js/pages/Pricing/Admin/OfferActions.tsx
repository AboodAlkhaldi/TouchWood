import { lazy, Suspense, useMemo, useRef, useState, type RefObject } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { PickedProductData, SaleRowData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { OfferMode, OfferStore } from './OfferDialog';
import { Guarded, MoreButton, useT } from './parts';

// The dialog - its pickers, its note, its date fields - loads when it is first opened, not with the page.
const OfferDialog = lazy(() => import('./OfferDialog').then((module) => ({ default: module.OfferDialog })));

/*
| An offer's ⋯ menu by its state (pricing.md §4.3.3 P2) - on Offers and on a product's Stores tab: scheduled
| **Change Offer…**, then **Remove Offer…**; running **Change End…**, then **End Offer…**; ended **View
| Offer…**. A scheduled offer whose size lost its price since cannot be changed: its item is drawn out of
| reach with the reason. Changing needs Edit Prices and Sales; viewing any of the three jobs. Remove and End
| ask first, each toast sharing its button's verb.
*/

type Open = { mode: OfferMode | 'end' | 'remove'; sale: SaleRowData | null; product?: PickedProductData | null; sizes?: string[] };

/** What End Offer or Remove Offer asks about. */
type Confirming = { kind: 'end' | 'remove'; sale: SaleRowData };

/** Every offer's ⋯ on the screen, in the order they are drawn (OfferMenu's `offer-actions-{id}`). */
const OFFER_MENUS = '[data-test^="offer-actions-"]';

/**
 * Where focus may go back once End or Remove closes, besides the opener's ⋯: the next offer's ⋯, the one
 * before, then the screen's Add Offer - on Offers, or in the opener's own store on a product's Stores tab.
 */
function nearOpener(trigger: HTMLElement | null): HTMLElement[] {
    const menus = Array.from(document.querySelectorAll<HTMLElement>(OFFER_MENUS));
    const at = trigger === null ? -1 : menus.indexOf(trigger);
    const add =
        document.querySelector<HTMLElement>('[data-test="add-offer"]') ??
        trigger?.closest('[data-test^="pricing-section-"]')?.querySelector<HTMLElement>('[data-test^="section-add-offer-"]') ??
        null;

    return [at === -1 ? undefined : menus[at + 1], at === -1 ? undefined : menus[at - 1], add].filter((element): element is HTMLElement => element !== undefined && element !== null);
}

/** The offer dialogs a screen opens, drawn once for all its rows. */
export function useOfferDialogs(store: OfferStore) {
    const [open, setOpen] = useState<Open | null>(null);
    // End and Remove stay mounted while they close, so focus is given back (useReturnFocus) - unmounted at
    // once, it fell to the page's start.
    const [confirming, setConfirming] = useState<Confirming | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    // Taken as it opens (nearOpener): a removed offer's row is gone once its dialog closes - and an ended
    // one's too, on a list of running offers.
    const near = useRef<HTMLElement[]>([]);
    // Where focus goes back, read as the dialog closes: the opener's ⋯ while its row is still there, else the
    // first of the others still on the page.
    const back = useMemo<RefObject<HTMLElement | null>>(
        () => ({
            get current() {
                return [opener.current, ...near.current].find((element) => element !== null && element.isConnected) ?? null;
            },
        }),
        [],
    );
    const close = (next: boolean) => (next ? undefined : setOpen(null));

    return {
        open: (next: Open, trigger: HTMLElement | null) => {
            opener.current = trigger;

            if (next.mode === 'end' || next.mode === 'remove') {
                near.current = nearOpener(trigger);

                if (next.sale !== null) {
                    setConfirming({ kind: next.mode, sale: next.sale });
                    setConfirmOpen(true);
                }

                return;
            }

            near.current = [];
            setOpen(next);
        },
        dialogs: (
            <>
                {confirming === null ? null : (
                    <ConfirmOffer kind={confirming.kind} sale={confirming.sale} open={confirmOpen} onOpenChange={setConfirmOpen} returnFocusTo={back} />
                )}
                {open === null || open.mode === 'end' || open.mode === 'remove' ? null : (
                    <Suspense fallback={null}>
                        <OfferDialog mode={open.mode} store={store} sale={open.sale} product={open.product ?? null} sizes={open.sizes ?? []} open onOpenChange={close} returnFocusTo={opener} />
                    </Suspense>
                )}
            </>
        ),
    };
}

export function OfferMenu({ sale, mayEdit, name, onOpen }: { sale: SaleRowData; mayEdit: boolean; name: string; onOpen: (mode: Open['mode'], trigger: HTMLElement | null) => void }) {
    const t = useT();
    const more = useRef<HTMLButtonElement>(null);

    // Nothing the reader may do to it: no menu is drawn.
    if (!mayEdit && sale.state !== 'ENDED') {
        return null;
    }

    const unpriced = sale.unpricedSizeNames[0];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} data-test={`offer-actions-${sale.id}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                {sale.state === 'SCHEDULED' ? (
                    <>
                        <Guarded label={t('pricing::admin.offer.change_item')} reason={unpriced === undefined ? null : t('pricing::admin.offer.unpriced', { size: unpriced })} onSelect={() => onOpen('change', more.current)} test="change-offer" />
                        <DropdownMenuSeparator />
                        <Guarded destructive label={t('pricing::admin.offer.remove_item')} onSelect={() => onOpen('remove', more.current)} test="remove-offer" />
                    </>
                ) : sale.state === 'RUNNING' ? (
                    <>
                        <Guarded label={t('pricing::admin.offer.change_end_item')} onSelect={() => onOpen('change_end', more.current)} test="change-end-offer" />
                        <DropdownMenuSeparator />
                        <Guarded destructive label={t('pricing::admin.offer.end_item')} onSelect={() => onOpen('end', more.current)} test="end-offer" />
                    </>
                ) : (
                    <Guarded label={t('pricing::admin.offer.view_item')} onSelect={() => onOpen('view', more.current)} test="view-offer" />
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** End Offer / Remove Offer: asked first, the toast saying the button's verb. */
function ConfirmOffer({
    kind,
    sale,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    kind: 'end' | 'remove';
    sale: SaleRowData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: RefObject<HTMLElement | null>;
}) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const title = kind === 'end' ? t('pricing::admin.offer.end') : t('pricing::admin.offer.remove');

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={kind === 'end' ? t('pricing::admin.offer.end_body') : t('pricing::admin.offer.remove_body')}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/offers/${sale.id}/${kind}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test={`confirm-${kind}-offer`}
                >
                    {title}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
