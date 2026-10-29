import { lazy, Suspense, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { AddressLines } from '@/components/AddressLines';
import { ActionButton } from '@/components/ActionButton';
import { DialogError } from '@/components/FormError';
import { Note } from '@/components/Note';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { Spinner } from '@/components/ui/spinner';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useReturnFocus } from '@/lib/use-return-focus';
import type {
    AddressBookStore,
    AddressRow,
    CustomerAccountPage,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

// The form, its place pickers and the command list under them load only when Add Address or Edit
// Address is pressed - not with every account page (frontend.md §5, the page budget).
const AddressForm = lazy(() => import('./AddressForm').then((module) => ({ default: module.AddressFormLoader })));

/*
| F9 - the address book (frontend.md §3.6), on shadcn's parts with Geist's rules (§1.11).
|
| **Grouped by store, because an address belongs to the store it is saved in** (access.md amendment
| 76(a)), whatever its country. The fields are the store's - administrative area, city, district,
| street, building - and they are data a staff member changes without a deploy, so this screen draws
| whatever the store's format says and nothing it hard-codes. The country is picked from Platform's
| address countries, and where it has a place list the region, city and district are picked from it
| (76(b), (d)).
|
| Every store is listed, not only the ones with an address in them: a customer may shop in any of
| them whichever one they registered in, and this is where they say where to deliver. A store with
| nothing in it yet is shadcn's Empty, outlined, with the one action that fills it.
|
| **No map.** The pin is left empty in this stage - a map needs a paid provider and none is chosen
| (decided 2026-09-19) - so what a courier gets is exactly what is written here.
|
| Opened from another page to add an address - the company form, say - the tab carries that page's
| name, and saving an address goes back to it (access.md amendment 51). The server decides whether
| the name is one of this account's pages; the tab only passes it on.
*/

type Props = {
    account: CustomerAccountPage;
};

export function AddressesTab({ account }: Props) {
    const t = useTranslator();

    return (
        <div className="grid gap-8">
            <p className="text-copy-14 text-ink-muted">{t('access::account.addresses_hint')}</p>

            {account.addresses.map((store) => (
                <StoreAddresses key={store.storeId} store={store} returnTo={account.returnTo} />
            ))}
        </div>
    );
}

function StoreAddresses({ store, returnTo }: { store: AddressBookStore; returnTo: string | null }) {
    const t = useTranslator();
    const [editing, setEditing] = useState<AddressRow | 'new' | null>(null);
    const heading = `addresses-${store.storeCode}-title`;

    // A country that holds as many addresses as it allows cannot take another: the button stays,
    // out of reach, and says why (Geist's rule for a disabled button) rather than vanishing.
    const add = (
        <ActionButton
            variant="outline"
            size="sm"
            className="w-fit"
            data-test={`add-address-${store.storeCode}`}
            disabledReason={store.full ? t('access::account.address_full', { count: store.limit }) : undefined}
            onClick={() => setEditing('new')}
        >
            {t('access::account.add_address')}
        </ActionButton>
    );

    return (
        // Its id is where another store's form points an address that belongs here (Q2).
        <section id={`addresses-${store.storeCode}`} className="grid scroll-mt-20 gap-3" aria-labelledby={heading} data-test={`addresses-${store.storeCode}`}>
            <h2 id={heading} className="text-heading-16 text-ink">
                {store.storeName}
            </h2>

            {/* A country we do not deliver to yet has no form at all: offering one and refusing it
                afterwards teaches nobody anything (access.md §1.9). One country of several is
                closed, so a neutral Note in its place rather than a page-wide message. */}
            {store.hasFormat ? null : <Note variant="secondary">{t('access::account.no_format')}</Note>}

            {store.hasFormat ? (
                <>
                    {store.addresses.length === 0 ? (
                        // Nothing to list yet - unless the form for the first one is already open.
                        editing === null ? (
                            <Empty className="border border-dashed border-line-strong p-6 md:p-8">
                                <EmptyHeader>
                                    <EmptyTitle className="text-heading-16 text-ink">{t('access::account.no_addresses_title')}</EmptyTitle>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('access::account.no_addresses')}</EmptyDescription>
                                </EmptyHeader>
                                <EmptyContent>{add}</EmptyContent>
                            </Empty>
                        ) : null
                    ) : (
                        <>
                            <ItemGroup className="material-base overflow-hidden">
                                {store.addresses.map((address, index) => (
                                    <div key={address.id} role="listitem">
                                        {index === 0 ? null : <ItemSeparator className="my-0" />}
                                        <SavedAddress address={address} returnTo={returnTo} onEdit={() => setEditing(address)} />
                                    </div>
                                ))}
                            </ItemGroup>

                            {editing === null ? add : null}
                        </>
                    )}

                    {editing === null ? null : (
                        <Suspense fallback={<Spinner className="text-ink-muted" aria-label={t('ui.loading')} />}>
                        <AddressForm
                            // A form of its own per address: Edit on another row starts it afresh
                            // rather than keeping the first one's values (the review of this job).
                            key={editing === 'new' ? 'new' : editing.id}
                            store={store}
                            address={editing === 'new' ? null : editing}
                            returnTo={returnTo}
                            onDone={() => setEditing(null)}
                        />
                        </Suspense>
                    )}
                </>
            ) : null}
        </section>
    );
}

/**
 * One saved address as it is listed: the store's own layout, which is what a courier is given.
 *
 * An address the country has outgrown says so here rather than at checkout, where it would stop an
 * order somebody is in the middle of placing (amendment 41).
 *
 * Geist's Entity rule: a row holds one or two controls, and the rest go into a Dots Menu with the
 * destructive one last, after a divider. So Edit Address stays on the row - it is what an address
 * book is opened for - and Set as Usual Address and Delete Address… are in the ⋯ menu.
 */
function SavedAddress({ address, returnTo, onEdit }: { address: AddressRow; returnTo: string | null; onEdit: () => void }) {
    const t = useTranslator();
    const link = useLink();
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [defaulting, setDefaulting] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const returnFocus = useReturnFocus(confirming, more);
    // The way back goes on with the tab after these changes too (amendment 52).
    const carried = returnTo === null ? {} : { return: returnTo };
    const title = `address-${address.id}-title`;

    return (
        <Item size="sm" className="rounded-none px-4" data-test={`address-${address.id}`}>
            <ItemContent className="min-w-0 gap-0.5">
                <ItemTitle id={title} className="text-label-14 font-medium text-ink">
                    {address.label}
                    {address.isDefault ? <Badge className={tone('blue-subtle')}>{t('access::account.is_default')}</Badge> : null}
                </ItemTitle>

                <ItemDescription className="text-copy-13 text-ink-muted">{address.recipientName}</ItemDescription>

                {/* The store's own template, which may hold several lines. */}
                <ItemDescription className="line-clamp-none text-copy-13 text-ink-muted">
                    <AddressLines text={address.formatted} />
                </ItemDescription>

                {/* Typed before its country had a place list (76(j)): which gap it is, said plainly. */}
                {address.isComplete ? null : (
                    <Note variant="warning" size="small" className="mt-2" data-test={`address-incomplete-${address.id}`}>
                        {address.needsPlaces ? t('access::account.places.needs_places') : t('access::account.address_incomplete')}
                    </Note>
                )}
            </ItemContent>

            <ItemActions className="self-start">
                {/* Described by the address's name, so each of the identical buttons says which it edits. */}
                <Button type="button" variant="outline" size="sm" onClick={onEdit} aria-describedby={title} data-test={`edit-address-${address.id}`}>
                    {t('access::account.edit_address')}
                </Button>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            ref={more}
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`${t('ui.more_actions')}: ${address.label}`}
                            aria-busy={defaulting || undefined}
                            title={t('ui.more_actions')}
                            data-test={`address-menu-${address.id}`}
                        >
                            {/* A menu closes as its item is chosen, so the item cannot show that it
                                is busy: the ⋯ button that holds it does, until the answer is back. */}
                            {defaulting ? <Spinner aria-label={t('ui.loading')} /> : <MoreHorizontal aria-hidden="true" />}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-52">
                        {address.isDefault ? null : (
                            <>
                                <DropdownMenuItem
                                    disabled={defaulting}
                                    data-test={`make-default-${address.id}`}
                                    onSelect={() =>
                                        router.post(link('storefront.account.addresses.default', { address: address.id }), carried, {
                                            preserveScroll: true,
                                            onStart: () => setDefaulting(true),
                                            onFinish: () => setDefaulting(false),
                                        })
                                    }
                                >
                                    {t('access::account.make_default')}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                            </>
                        )}
                        {/* Its own words, ending in "…" because it opens a dialog (frontend.md
                            1.10); the dialog's confirm button says the same without them. */}
                        <DropdownMenuItem variant="destructive" data-test={`delete-address-${address.id}`} onSelect={() => setConfirming(true)}>
                            {t('access::account.delete_address_open')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </ItemActions>

            {/* shadcn's AlertDialog: it starts on Cancel and says it is an alert dialog. A plain
                confirmation rather than a typed name: an address is quick to write again, and
                orders already placed keep their own copy. It stays open on a refusal, with the
                reason inside it, so the person can try again. */}
            <AlertDialog open={confirming} onOpenChange={(open) => (deleting ? undefined : setConfirming(open))}>
                <AlertDialogContent onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 data-[size=default]:sm:max-w-md">
                    <div className="grid gap-4 p-6">
                        <AlertDialogHeader>
                            <AlertDialogTitle className="text-heading-20 text-ink">
                                {t('access::account.confirm_delete_address', { label: address.label })}
                            </AlertDialogTitle>
                            <AlertDialogDescription className="text-copy-14 text-ink-muted">
                                {t('access::account.confirm_delete_address_body')}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {/* Inside the dialog: the toast sits under its backdrop and is hidden from
                            screen readers while it is open (the review of the move). */}
                        <DialogError open={confirming} />
                    </div>
                    <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                        <AlertDialogCancel disabled={deleting} data-test="modal-cancel">
                            {t('ui.cancel')}
                        </AlertDialogCancel>
                        <ActionButton
                            variant="destructive"
                            loading={deleting}
                            data-test={`confirm-delete-${address.id}`}
                            onClick={() =>
                                router.post(link('storefront.account.addresses.delete', { address: address.id }), carried, {
                                    preserveScroll: true,
                                    onStart: () => setDeleting(true),
                                    // Closed once it is gone; a refusal keeps it open.
                                    onSuccess: () => setConfirming(false),
                                    onFinish: () => setDeleting(false),
                                })
                            }
                        >
                            {t('access::account.delete_address')}
                        </ActionButton>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Item>
    );
}
