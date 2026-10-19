import { lazy, Suspense, useRef, useState, type RefObject } from 'react';
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

/** The offer dialogs a screen opens, drawn once for all its rows. */
export function useOfferDialogs(store: OfferStore) {
    const [open, setOpen] = useState<Open | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (next: boolean) => (next ? undefined : setOpen(null));

    return {
        open: (next: Open, trigger: HTMLElement | null) => {
            opener.current = trigger;
            setOpen(next);
        },
        dialogs:
            open === null ? null : open.mode === 'end' || open.mode === 'remove' ? (
                open.sale === null ? null : (
                    <ConfirmOffer kind={open.mode} sale={open.sale} onOpenChange={close} returnFocusTo={opener} />
                )
            ) : (
                <Suspense fallback={null}>
                    <OfferDialog mode={open.mode} store={store} sale={open.sale} product={open.product ?? null} sizes={open.sizes ?? []} open onOpenChange={close} returnFocusTo={opener} />
                </Suspense>
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
function ConfirmOffer({ kind, sale, onOpenChange, returnFocusTo }: { kind: 'end' | 'remove'; sale: SaleRowData; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const title = kind === 'end' ? t('pricing::admin.offer.end') : t('pricing::admin.offer.remove');

    return (
        <PanelDialog
            destructive
            open
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
