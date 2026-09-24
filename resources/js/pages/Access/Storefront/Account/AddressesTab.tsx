import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { toLatinDigits } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    AddressBookStore,
    AddressRow,
    CustomerAccountPage,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F9 - the address book (frontend.md §3.6).
|
| **Grouped by country, because an address belongs to one.** The fields themselves are that
| country's - administrative area, city, district, street, building - and they are data a staff
| member changes without a deploy, so this screen draws whatever the store's format says and
| nothing it hard-codes. An address in another country is a new address there, never this one with
| the country changed (access.md §1.9).
|
| Every country is listed, not only the ones with an address in them: a customer may shop in any of
| them whichever one they registered in, and this is where they say where to deliver.
|
| **No map.** The pin is left empty in this stage - a map needs a paid provider and none is chosen
| (decided 2026-09-19) - so what a courier gets is exactly what is written here.
*/

type Props = {
    account: CustomerAccountPage;
};

export function AddressesTab({ account }: Props) {
    const t = useTranslator();

    return (
        <div className="grid gap-8">
            <p className="text-sm text-ink-muted">{t('access::account.addresses_hint')}</p>

            {account.addresses.map((store) => (
                <StoreAddresses key={store.storeId} store={store} />
            ))}
        </div>
    );
}

function StoreAddresses({ store }: { store: AddressBookStore }) {
    const t = useTranslator();
    const [editing, setEditing] = useState<AddressRow | 'new' | null>(null);

    return (
        <section className="grid gap-3" data-test={`addresses-${store.storeCode}`}>
            <h2 className="text-sm font-semibold text-ink">{store.storeName}</h2>

            {/* A country we do not deliver to yet has no form at all: offering one and refusing it
                afterwards teaches nobody anything (access.md §1.9). */}
            {store.hasFormat ? null : (
                <p className="rounded-md border border-line bg-surface-sunken px-4 py-3 text-sm text-ink-muted">
                    {t('access::account.no_format')}
                </p>
            )}

            {store.hasFormat ? (
                <>
                    {store.addresses.length === 0 ? (
                        <p className="text-sm text-ink-muted">{t('access::account.no_addresses')}</p>
                    ) : (
                        <ul className="grid gap-3">
                            {store.addresses.map((address) => (
                                <li key={address.id}>
                                    <SavedAddress
                                        address={address}
                                        onEdit={() => setEditing(address)}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    {store.full ? (
                        <p className="text-sm text-ink-muted">
                            {t('access::account.address_full', { count: store.limit })}
                        </p>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="w-fit"
                            data-test={`add-address-${store.storeCode}`}
                            onClick={() => setEditing('new')}
                        >
                            {t('access::account.add_address')}
                        </Button>
                    )}

                    {editing === null ? null : (
                        <AddressForm
                            store={store}
                            address={editing === 'new' ? null : editing}
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
 */
function SavedAddress({ address, onEdit }: { address: AddressRow; onEdit: () => void }) {
    const t = useTranslator();
    const link = useLink();
    const [confirming, setConfirming] = useState(false);

    return (
        <div className="grid gap-2 rounded-lg border border-line p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="grid gap-0.5">
                    <p className="text-sm font-medium text-ink">
                        {address.label}
                        {address.isDefault ? (
                            <span className="ms-2 rounded-md bg-good-soft px-2 py-0.5 text-xs text-good">
                                {t('access::account.is_default')}
                            </span>
                        ) : null}
                    </p>

                    <p className="text-sm text-ink-muted">{address.recipientName}</p>

                    {/* A dialled number reads left to right, in Latin digits, in any language. */}
                    <p className="tw-figure text-sm text-ink-muted" dir="ltr">
                        {address.phone}
                    </p>

                    {/* The store's own template, which may hold several lines. */}
                    <p className="whitespace-pre-line text-sm text-ink-muted">
                        {address.formatted}
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {address.isDefault ? null : (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-test={`make-default-${address.id}`}
                            onClick={() =>
                                router.post(
                                    link('storefront.account.addresses.default', {
                                        address: address.id,
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('access::account.make_default')}
                        </Button>
                    )}

                    <Button type="button" variant="outline" size="sm" onClick={onEdit}>
                        {t('access::account.edit_address')}
                    </Button>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-test={`delete-address-${address.id}`}
                        onClick={() => setConfirming(true)}
                    >
                        {t('access::account.delete_address')}
                    </Button>
                </div>
            </div>

            {address.isComplete ? null : (
                <p className="rounded-md border border-warn/30 bg-warn-soft px-3 py-2 text-xs text-warn">
                    {t('access::account.address_incomplete')}
                </p>
            )}

            {/* Asked in the page, not in the browser's own dialog: window.confirm cannot be driven
                by a test, which is how the panel's media delete went untested for a step. */}
            {confirming ? (
                <div className="grid gap-2 rounded-md border border-bad/30 bg-bad-soft p-3">
                    <p className="text-sm font-medium text-bad">
                        {t('access::account.confirm_delete_address', { label: address.label })}
                    </p>
                    <p className="text-xs text-ink-muted">
                        {t('access::account.confirm_delete_address_body')}
                    </p>

                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            data-test={`confirm-delete-${address.id}`}
                            onClick={() =>
                                router.post(
                                    link('storefront.account.addresses.delete', {
                                        address: address.id,
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('access::account.delete_address')}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setConfirming(false)}
                        >
                            {t('access::account.cancel')}
                        </Button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

/**
 * The form for one address, new or being changed.
 *
 * The fields under the first three are the **store's**, drawn from its format: their labels, their
 * order, whether each is required and how long it may be all come from the server, so a country
 * whose rules change needs no change here.
 */
function AddressForm({
    store,
    address,
    onDone,
}: {
    store: AddressBookStore;
    address: AddressRow | null;
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
            className="grid gap-5 rounded-lg border border-brand/30 bg-brand-soft/20 p-4"
            data-test={`address-form-${store.storeCode}`}
        >
            <FormError />

            <Field
                id={`label-${store.storeCode}`}
                label={t('access::account.address_label')}
                hint={t('access::account.address_label_hint')}
                error={form.errors.label}
            >
                <Input
                    id={`label-${store.storeCode}`}
                    required
                    value={form.data.label}
                    onChange={(event) => form.setData('label', event.target.value)}
                />
            </Field>

            <div className="grid gap-5 sm:grid-cols-2">
                <Field
                    id={`recipient-${store.storeCode}`}
                    label={t('access::account.recipient_name')}
                    error={form.errors.recipient_name}
                >
                    <Input
                        id={`recipient-${store.storeCode}`}
                        required
                        value={form.data.recipient_name}
                        onChange={(event) => form.setData('recipient_name', event.target.value)}
                    />
                </Field>

                <Field
                    id={`phone-${store.storeCode}`}
                    label={t('access::account.address_phone')}
                    hint={t('access::account.address_phone_hint')}
                    error={form.errors.phone}
                >
                    <Input
                        id={`phone-${store.storeCode}`}
                        type="tel"
                        required
                        dir="ltr"
                        className="tw-figure"
                        value={form.data.phone}
                        onChange={(event) => form.setData('phone', toLatinDigits(event.target.value))}
                    />
                </Field>
            </div>

            {store.fields.map((field) => (
                <Field
                    key={field.key}
                    id={`${store.storeCode}-${field.key}`}
                    label={field.label}
                    error={form.errors[`fields.${field.key}` as keyof typeof form.errors] as string}
                >
                    <Input
                        id={`${store.storeCode}-${field.key}`}
                        name={`fields[${field.key}]`}
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
                </Field>
            ))}

            {/* The first address in a country becomes its default on its own, so this is only ever
                a way to move the flag - never a way to leave a country without one. */}
            <label className="flex items-center gap-2 text-sm text-ink">
                <Checkbox
                    checked={form.data.is_default}
                    onCheckedChange={(checked) => form.setData('is_default', checked === true)}
                />
                {t('access::account.default_address')}
            </label>

            <div className="flex gap-2">
                <Button
                    type="submit"
                    disabled={form.processing}
                    data-test={`save-address-${store.storeCode}`}
                >
                    {t('access::account.save')}
                </Button>

                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('access::account.cancel')}
                </Button>
            </div>
        </form>
    );
}
