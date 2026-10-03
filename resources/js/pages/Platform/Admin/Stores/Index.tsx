import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { DialogError, FormError } from '@/components/FormError';
import { Badge, Button, Description, EmptyState, Input, Modal, ModalCancel, Note, Select } from '@/components/geist';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { StoreRow, StoresPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E1 - the stores, with E2 as the form on each card (frontend.md §3.5), in Geist's parts (1.10).
|
| Only the stores this person may see are here: Platform's read model answered that, store by
| store, and the edit form appears only on the ones they may change. Offering is not allowing - the
| handler behind the form asks again, against that one store.
|
| No store is made here. Opening a country stays a console command, so a store is created complete
| and can never exist half-configured [DECIDED 2026-09-19]; the screen says so rather than leaving
| somebody hunting for a button.
|
| The code, the country and the currency are shown and cannot be changed: they are what the store
| *is*. Platform refuses an attempt to change them rather than ignoring it, and the card says so -
| as Geist's Description, beside the sentence that explains them, in the footer by the save button
| as Geist's Fieldset lays a section out.
|
| The on/off switch (platform.md §1.1, §9.5; owner, 2026-10-01) is a Super Admin's alone, so only
| they see a store's state and an off store at all. Turning one on just posts; turning one off is
| asked first, in a destructive dialog whose button and toast share the verb ("Turn Store Off" →
| "Store turned off"). The base store is never off: its button stays, disabled, saying why.
*/

type Props = StoresPage;

export default function Index({ stores, timezones, maySwitch }: Props) {
    const t = useTranslator();
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <AdminLayout title={t('platform::admin_stores.title')} subtitle={t('platform::admin_stores.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                {stores.length === 0 ? (
                    <EmptyState
                        title={t('platform::admin_stores.none_title')}
                        description={t('platform::admin_stores.no_stores')}
                    />
                ) : (
                    stores.map((store) => (
                        <Card
                            key={store.id}
                            store={store}
                            maySwitch={maySwitch}
                            timezones={timezones}
                            open={editing === store.id}
                            onOpen={() => setEditing(editing === store.id ? null : store.id)}
                            onDone={() => setEditing(null)}
                        />
                    ))
                )}

                <p className="text-copy-13 text-ink-muted">{t('platform::admin_stores.no_new_store')}</p>
            </div>
        </AdminLayout>
    );
}

type CardProps = {
    store: StoreRow;
    maySwitch: boolean;
    timezones: string[];
    open: boolean;
    onOpen: () => void;
    onDone: () => void;
};

function Card({ store, maySwitch, timezones, open, onOpen, onDone }: CardProps) {
    const t = useTranslator();
    const [confirmingOff, setConfirmingOff] = useState(false);
    const [switching, setSwitching] = useState(false);

    const turn = (way: 'activate' | 'deactivate', onSuccess?: () => void) =>
        router.post(`/admin/stores/${store.code}/${way}`, {}, {
            preserveScroll: true,
            onStart: () => setSwitching(true),
            onSuccess,
            onFinish: () => setSwitching(false),
        });

    const form = useForm({
        name_ar: store.nameAr,
        name_en: store.nameEn,
        tax_rate: store.taxRatePercent,
        timezone: store.timezone,
        position: String(store.position),
    });

    return (
        <section className="material-base overflow-hidden">
            <header className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div className="grid gap-0.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-heading-16 text-ink">{store.name}</h2>
                        {/* The state is only a Super Admin's to see, as an off store is (§1.6). */}
                        {maySwitch ? (
                            <Badge variant={store.isActive ? 'green-subtle' : 'gray-subtle'} data-test={`state-${store.code}`}>
                                {t(store.isActive ? 'platform::admin_stores.on' : 'platform::admin_stores.off')}
                            </Badge>
                        ) : null}
                        {maySwitch && store.isBase ? <Badge variant="blue-subtle">{t('platform::admin_stores.base')}</Badge> : null}
                    </div>
                    <p className="text-copy-13 text-ink-muted">
                        <span className="tw-figure">{store.code.toUpperCase()}</span> ·{' '}
                        {store.currencyCode} {store.currencySymbol} ·{' '}
                        <span className="tw-figure">{store.taxRatePercent}%</span> · {store.timezone}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {maySwitch && !store.isActive ? (
                        <Button
                            type="secondary"
                            loading={switching}
                            disabledReason={store.switchable ? undefined : t('platform::admin_stores.base_hint')}
                            data-test={`turn-on-${store.code}`}
                            onClick={() => turn('activate')}
                        >
                            {t('platform::admin_stores.turn_on')}
                        </Button>
                    ) : null}
                    {maySwitch && store.isActive ? (
                        <Button
                            type="error"
                            disabledReason={store.switchable ? undefined : t('platform::admin_stores.base_hint')}
                            data-test={`turn-off-${store.code}`}
                            onClick={() => setConfirmingOff(true)}
                        >
                            {t('platform::admin_stores.turn_off')}
                        </Button>
                    ) : null}
                    {store.editable ? (
                        <Button type="secondary" data-test={`edit-${store.code}`} onClick={onOpen}>
                            {t(open ? 'platform::admin_stores.cancel' : 'platform::admin_stores.edit')}
                        </Button>
                    ) : null}
                </div>
            </header>

            {maySwitch && !store.isActive ? (
                <div className="px-5 pb-4">
                    <Note variant="secondary" size="small">
                        {t('platform::admin_stores.off_hint')}
                    </Note>
                </div>
            ) : null}

            <Modal
                open={confirmingOff}
                onOpenChange={(next) => (switching ? undefined : setConfirmingOff(next))}
                destructive
                title={t('platform::admin_stores.turn_off')}
                description={t('platform::admin_stores.turn_off_confirm', { name: store.name })}
                actions={
                    <>
                        <ModalCancel onClick={() => setConfirmingOff(false)} disabled={switching} />
                        <Button
                            type="error"
                            loading={switching}
                            data-test={`confirm-turn-off-${store.code}`}
                            onClick={() => turn('deactivate', () => setConfirmingOff(false))}
                        >
                            {t('platform::admin_stores.turn_off')}
                        </Button>
                    </>
                }
            >
                <DialogError open={confirmingOff} />
            </Modal>

            {open ? (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/admin/stores/${store.code}`, { onSuccess: onDone });
                    }}
                    className="border-t border-line"
                >
                    <div className="grid gap-4 p-5 sm:grid-cols-2">
                        <Input
                            id={`${store.id}-name_ar`}
                            label={t('platform::admin_stores.name_ar')}
                            error={form.errors.name_ar}
                            required
                            lang="ar"
                            value={form.data.name_ar}
                            onChange={(event) => form.setData('name_ar', event.target.value)}
                        />

                        <Input
                            id={`${store.id}-name_en`}
                            label={t('platform::admin_stores.name_en')}
                            error={form.errors.name_en}
                            required
                            lang="en"
                            dir="ltr"
                            value={form.data.name_en}
                            onChange={(event) => form.setData('name_en', event.target.value)}
                        />

                        <Input
                            id={`${store.id}-tax_rate`}
                            label={t('platform::admin_stores.tax_rate')}
                            helper={t('platform::admin_stores.tax_rate_hint')}
                            error={form.errors.tax_rate}
                            required
                            dir="ltr"
                            inputMode="decimal"
                            value={form.data.tax_rate}
                            // A rate typed on an Arabic keyboard is the same rate (frontend.md §1.8).
                            onChange={(event) => form.setData('tax_rate', toLatinDigits(event.target.value))}
                        />

                        <Input
                            id={`${store.id}-position`}
                            label={t('platform::admin_stores.position')}
                            helper={t('platform::admin_stores.position_hint')}
                            error={form.errors.position}
                            required
                            dir="ltr"
                            inputMode="numeric"
                            value={form.data.position}
                            onChange={(event) => form.setData('position', toLatinDigits(event.target.value))}
                        />

                        <Select
                            id={`${store.id}-timezone`}
                            label={t('platform::admin_stores.timezone')}
                            error={form.errors.timezone}
                            className="sm:col-span-2"
                            dir="ltr"
                            value={form.data.timezone}
                            onChange={(event) => form.setData('timezone', event.target.value)}
                        >
                            {timezones.map((zone) => (
                                <option key={zone} value={zone}>
                                    {zone}
                                </option>
                            ))}
                        </Select>

                        {/* Shown, never editable: what the store is, decided when the country was opened. */}
                        <div className="sm:col-span-2">
                            <Description
                                columns={3}
                                items={[
                                    { title: t('platform::admin_stores.code'), content: <Fixed value={store.code.toUpperCase()} /> },
                                    { title: t('platform::admin_stores.country'), content: <Fixed value={store.countryCode} /> },
                                    {
                                        title: t('platform::admin_stores.currency'),
                                        content: <Fixed value={`${store.currencyCode} ${store.currencySymbol}`} />,
                                    },
                                ]}
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-surface-sunken px-5 py-3">
                        <p className="text-copy-13 text-ink-muted">{t('platform::admin_stores.immutable')}</p>
                        <Button typeName="submit" loading={form.processing}>
                            {t('platform::admin_stores.save')}
                        </Button>
                    </div>
                </form>
            ) : null}
        </section>
    );
}

/** A value that is what the store is: a code, read left to right in either language. */
function Fixed({ value }: { value: string }) {
    return (
        <span className="tw-figure" dir="ltr">
            {value}
        </span>
    );
}
