import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import { Badge, Button, Checkbox, EmptyState, Input, Modal, ModalCancel, Note } from '@/components/geist';
import { toLatinDigits } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    AddressBookStore,
    AddressRow,
    CustomerAccountPage,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F9 - the address book (frontend.md §3.6), on Geist's parts (1.10).
|
| **Grouped by country, because an address belongs to one.** The fields themselves are that
| country's - administrative area, city, district, street, building - and they are data a staff
| member changes without a deploy, so this screen draws whatever the store's format says and
| nothing it hard-codes. An address in another country is a new address there, never this one with
| the country changed (access.md §1.9).
|
| Every country is listed, not only the ones with an address in them: a customer may shop in any of
| them whichever one they registered in, and this is where they say where to deliver. A country
| with nothing in it yet is Geist's Empty State, with the one action that fills it.
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

function StoreAddresses({
    store,
    returnTo,
}: {
    store: AddressBookStore;
    returnTo: string | null;
}) {
    const t = useTranslator();
    const [editing, setEditing] = useState<AddressRow | 'new' | null>(null);

    // A country that holds as many addresses as it allows cannot take another: the button stays,
    // out of reach, and says why (Geist's rule for a disabled button) rather than vanishing.
    const add = (
        <Button
            type="secondary"
            size="small"
            className="w-fit"
            data-test={`add-address-${store.storeCode}`}
            disabledReason={store.full ? t('access::account.address_full', { count: store.limit }) : undefined}
            onClick={() => setEditing('new')}
        >
            {t('access::account.add_address')}
        </Button>
    );

    return (
        <section className="grid gap-3" data-test={`addresses-${store.storeCode}`}>
            <h2 className="text-heading-16 text-ink">{store.storeName}</h2>

            {/* A country we do not deliver to yet has no form at all: offering one and refusing it
                afterwards teaches nobody anything (access.md §1.9). One country of several is
                closed, so a neutral Note in its place rather than a page-wide message. */}
            {store.hasFormat ? null : <Note variant="secondary">{t('access::account.no_format')}</Note>}

            {store.hasFormat ? (
                <>
                    {store.addresses.length === 0 ? (
                        // Nothing to list yet - unless the form for the first one is already open.
                        editing === null ? (
                            <EmptyState
                                title={t('access::account.no_addresses_title')}
                                description={t('access::account.no_addresses')}
                                actions={add}
                            />
                        ) : null
                    ) : (
                        <>
                            <ul className="material-base divide-y divide-line">
                                {store.addresses.map((address) => (
                                    <li key={address.id}>
                                        <SavedAddress
                                            address={address}
                                            returnTo={returnTo}
                                            onEdit={() => setEditing(address)}
                                        />
                                    </li>
                                ))}
                            </ul>

                            {add}
                        </>
                    )}

                    {editing === null ? null : (
                        <AddressForm
                            store={store}
                            address={editing === 'new' ? null : editing}
                            returnTo={returnTo}
                            onDone={() => setEditing(null)}
                        />
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
 * Its three actions stay on the row rather than behind a menu: an address book is visited to do
 * exactly these things, and a customer should not have to hunt for them.
 */
function SavedAddress({
    address,
    returnTo,
    onEdit,
}: {
    address: AddressRow;
    returnTo: string | null;
    onEdit: () => void;
}) {
    const t = useTranslator();
    const link = useLink();
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    // The way back goes on with the tab after these changes too (amendment 52).
    const carried = returnTo === null ? {} : { return: returnTo };

    return (
        <div className="grid gap-3 px-4 py-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid min-w-0 gap-0.5">
                    <p className="flex flex-wrap items-center gap-2 text-label-14 font-medium text-ink">
                        {address.label}
                        {address.isDefault ? (
                            <Badge variant="blue-subtle" size="small">
                                {t('access::account.is_default')}
                            </Badge>
                        ) : null}
                    </p>

                    <p className="text-copy-13 text-ink-muted">{address.recipientName}</p>

                    {/* A dialled number reads left to right, in Latin digits, in any language. */}
                    <p className="tw-figure text-copy-13 text-ink-muted" dir="ltr">
                        {address.phone}
                    </p>

                    {/* The store's own template, which may hold several lines. */}
                    <p className="whitespace-pre-line text-copy-13 text-ink-muted">{address.formatted}</p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {address.isDefault ? null : (
                        <Button
                            type="tertiary"
                            size="small"
                            data-test={`make-default-${address.id}`}
                            onClick={() =>
                                router.post(
                                    link('storefront.account.addresses.default', {
                                        address: address.id,
                                    }),
                                    carried,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('access::account.make_default')}
                        </Button>
                    )}

                    <Button type="secondary" size="small" onClick={onEdit}>
                        {t('access::account.edit_address')}
                    </Button>

                    <Button
                        type="tertiary"
                        size="small"
                        data-test={`delete-address-${address.id}`}
                        onClick={() => setConfirming(true)}
                    >
                        {/* Its own words, ending in "…" because it opens a dialog (frontend.md
                            1.10); the dialog's confirm button says the same without them. */}
                        {t('access::account.delete_address_open')}
                    </Button>
                </div>
            </div>

            {address.isComplete ? null : (
                <Note variant="warning" size="small">
                    {t('access::account.address_incomplete')}
                </Note>
            )}

            {/* Asked in Geist's Modal, not in the browser's own dialog: window.confirm cannot be
                driven by a test, which is how the panel's media delete went untested for a step.
                A plain destructive Modal - focus starts on Cancel - rather than a typed name: an
                address is quick to write again, and orders already placed keep their own copy. */}
            <Modal
                open={confirming}
                onOpenChange={(open) => (deleting ? undefined : setConfirming(open))}
                title={t('access::account.confirm_delete_address', { label: address.label })}
                description={t('access::account.confirm_delete_address_body')}
                destructive
                actions={
                    <>
                        <ModalCancel onClick={() => setConfirming(false)} disabled={deleting} />
                        <Button
                            type="error"
                            loading={deleting}
                            data-test={`confirm-delete-${address.id}`}
                            onClick={() =>
                                router.post(
                                    link('storefront.account.addresses.delete', {
                                        address: address.id,
                                    }),
                                    carried,
                                    {
                                        preserveScroll: true,
                                        onStart: () => setDeleting(true),
                                        // Closed once it is gone; a refusal keeps it open, with the
                                        // reason in the toast, so the person can try again.
                                        onSuccess: () => setConfirming(false),
                                        onFinish: () => setDeleting(false),
                                    },
                                )
                            }
                        >
                            {t('access::account.delete_address')}
                        </Button>
                    </>
                }
            />
        </div>
    );
}

/**
 * The form for one address, new or being changed, on a raised surface so it reads as the thing
 * being worked on.
 *
 * The fields under the first three are the **store's**, drawn from its format: their labels, their
 * order, whether each is required and how long it may be all come from the server, so a country
 * whose rules change needs no change here.
 */
function AddressForm({
    store,
    address,
    returnTo,
    onDone,
}: {
    store: AddressBookStore;
    address: AddressRow | null;
    returnTo: string | null;
    onDone: () => void;
}) {
    const t = useTranslator();
    const link = useLink();

    const form = useForm({
        store_id: store.storeId,
        address_id: address?.id ?? '',
        label: address?.label ?? '',
        recipient_name: address?.recipientName ?? '',
        phone: address?.phone ?? '',
        is_default: address?.isDefault ?? false,
        return: returnTo ?? '',
        fields: Object.fromEntries(
            store.fields.map((field) => [field.key, address?.fields[field.key] ?? '']),
        ) as Record<string, string>,
    });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(link('storefront.account.addresses.save'), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="material-small grid gap-5 p-4"
            data-test={`address-form-${store.storeCode}`}
        >
            <FormError />

            <Input
                id={`label-${store.storeCode}`}
                label={t('access::account.address_label')}
                helper={t('access::account.address_label_hint')}
                error={form.errors.label}
                required
                value={form.data.label}
                onChange={(event) => form.setData('label', event.target.value)}
            />

            <div className="grid gap-5 sm:grid-cols-2">
                <Input
                    id={`recipient-${store.storeCode}`}
                    label={t('access::account.recipient_name')}
                    error={form.errors.recipient_name}
                    required
                    value={form.data.recipient_name}
                    onChange={(event) => form.setData('recipient_name', event.target.value)}
                />

                {/* Typed in figures: the mono face is set on the box from Geist's frame, so the
                    label and helper keep the text face. Latin digits only - they are converted
                    as they are typed - so the mono face serves Arabic pages too (frontend.md 1.8). */}
                <Input
                    id={`phone-${store.storeCode}`}
                    type="tel"
                    label={t('access::account.address_phone')}
                    helper={t('access::account.address_phone_hint')}
                    error={form.errors.phone}
                    required
                    dir="ltr"
                    className="[&_input]:font-mono [&_input]:tabular-nums"
                    value={form.data.phone}
                    onChange={(event) => form.setData('phone', toLatinDigits(event.target.value))}
                />
            </div>

            {store.fields.map((field) => (
                <Input
                    key={field.key}
                    id={`${store.storeCode}-${field.key}`}
                    name={`fields[${field.key}]`}
                    label={field.label}
                    error={form.errors[`fields.${field.key}` as keyof typeof form.errors] as string}
                    required={field.required}
                    maxLength={field.maxLength}
                    value={form.data.fields[field.key] ?? ''}
                    onChange={(event) =>
                        form.setData('fields', {
                            ...form.data.fields,
                            [field.key]: event.target.value,
                        })
                    }
                />
            ))}

            {/* The first address in a country becomes its default on its own, so this is only ever
                a way to move the flag - never a way to leave a country without one. A checkbox
                rather than a Toggle: it means nothing until the form is saved. */}
            <Checkbox
                id={`default-${store.storeCode}`}
                checked={form.data.is_default}
                onChange={(checked) => form.setData('is_default', checked)}
            >
                {t('access::account.default_address')}
            </Checkbox>

            <div className="flex gap-2">
                <Button typeName="submit" loading={form.processing} data-test={`save-address-${store.storeCode}`}>
                    {t('access::account.save')}
                </Button>

                <Button type="tertiary" onClick={onDone}>
                    {t('access::account.cancel')}
                </Button>
            </div>
        </form>
    );
}
