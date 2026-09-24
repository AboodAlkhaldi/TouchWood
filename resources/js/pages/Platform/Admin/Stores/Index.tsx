import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { StoreRow, StoresPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E1 - the stores, with E2 as the form on each card (frontend.md §3.5).
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
| *is*. Platform refuses an attempt to change them rather than ignoring it, and the card says so.
*/

type Props = StoresPage;

export default function Index({ stores, timezones }: Props) {
    const t = useTranslator();
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <AdminLayout title={t('platform::admin_stores.title')} subtitle={t('platform::admin_stores.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                {stores.length === 0 ? (
                    <p className="rounded-lg border border-line bg-surface p-6 text-sm text-ink-muted">
                        {t('platform::admin_stores.no_stores')}
                    </p>
                ) : (
                    stores.map((store) => (
                        <Card
                            key={store.id}
                            store={store}
                            timezones={timezones}
                            open={editing === store.id}
                            onOpen={() => setEditing(editing === store.id ? null : store.id)}
                            onDone={() => setEditing(null)}
                        />
                    ))
                )}

                <p className="text-xs text-ink-muted">{t('platform::admin_stores.no_new_store')}</p>
            </div>
        </AdminLayout>
    );
}

type CardProps = {
    store: StoreRow;
    timezones: string[];
    open: boolean;
    onOpen: () => void;
    onDone: () => void;
};

function Card({ store, timezones, open, onOpen, onDone }: CardProps) {
    const t = useTranslator();

    const form = useForm({
        name_ar: store.nameAr,
        name_en: store.nameEn,
        tax_rate: store.taxRatePercent,
        timezone: store.timezone,
        position: String(store.position),
    });

    return (
        <section className="rounded-lg border border-line bg-surface shadow-card">
            <header className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div className="grid gap-0.5">
                    <h2 className="text-sm font-semibold text-ink">{store.name}</h2>
                    <p className="text-xs text-ink-muted">
                        <span className="tw-figure">{store.code.toUpperCase()}</span> ·{' '}
                        {store.currencyCode} {store.currencySymbol} ·{' '}
                        <span className="tw-figure">{store.taxRatePercent}%</span> · {store.timezone}
                    </p>
                </div>

                {store.editable ? (
                    <Button variant="outline" data-test={`edit-${store.code}`} onClick={onOpen}>
                        {t(open ? 'platform::admin_stores.cancel' : 'platform::admin_stores.edit')}
                    </Button>
                ) : null}
            </header>

            {open ? (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/admin/stores/${store.code}`, { onSuccess: onDone });
                    }}
                    className="grid gap-4 border-t border-line p-4 sm:grid-cols-2"
                >
                    <Field id={`${store.id}-name_ar`} label={t('platform::admin_stores.name_ar')} error={form.errors.name_ar}>
                        <Input
                            id={`${store.id}-name_ar`}
                            required
                            lang="ar"
                            value={form.data.name_ar}
                            onChange={(event) => form.setData('name_ar', event.target.value)}
                        />
                    </Field>

                    <Field id={`${store.id}-name_en`} label={t('platform::admin_stores.name_en')} error={form.errors.name_en}>
                        <Input
                            id={`${store.id}-name_en`}
                            required
                            lang="en"
                            dir="ltr"
                            value={form.data.name_en}
                            onChange={(event) => form.setData('name_en', event.target.value)}
                        />
                    </Field>

                    <Field
                        id={`${store.id}-tax_rate`}
                        label={t('platform::admin_stores.tax_rate')}
                        hint={t('platform::admin_stores.tax_rate_hint')}
                        error={form.errors.tax_rate}
                    >
                        <Input
                            id={`${store.id}-tax_rate`}
                            required
                            dir="ltr"
                            inputMode="decimal"
                            className="tw-figure"
                            value={form.data.tax_rate}
                            // A rate typed on an Arabic keyboard is the same rate (frontend.md §1.8).
                            onChange={(event) => form.setData('tax_rate', toLatinDigits(event.target.value))}
                        />
                    </Field>

                    <Field
                        id={`${store.id}-position`}
                        label={t('platform::admin_stores.position')}
                        hint={t('platform::admin_stores.position_hint')}
                        error={form.errors.position}
                    >
                        <Input
                            id={`${store.id}-position`}
                            required
                            dir="ltr"
                            inputMode="numeric"
                            className="tw-figure"
                            value={form.data.position}
                            onChange={(event) => form.setData('position', toLatinDigits(event.target.value))}
                        />
                    </Field>

                    <div className="sm:col-span-2">
                        <Field id={`${store.id}-timezone`} label={t('platform::admin_stores.timezone')} error={form.errors.timezone}>
                            <select
                                id={`${store.id}-timezone`}
                                dir="ltr"
                                value={form.data.timezone}
                                onChange={(event) => form.setData('timezone', event.target.value)}
                                className="h-9 w-full rounded-md border border-line-strong bg-surface px-3 text-sm text-ink"
                            >
                                {timezones.map((zone) => (
                                    <option key={zone} value={zone}>
                                        {zone}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>

                    <dl className="grid gap-2 text-sm sm:col-span-2 sm:grid-cols-3">
                        <Fixed label={t('platform::admin_stores.code')} value={store.code.toUpperCase()} />
                        <Fixed label={t('platform::admin_stores.country')} value={store.countryCode} />
                        <Fixed
                            label={t('platform::admin_stores.currency')}
                            value={`${store.currencyCode} ${store.currencySymbol}`}
                        />
                    </dl>

                    <p className="text-xs text-ink-muted sm:col-span-2">{t('platform::admin_stores.immutable')}</p>

                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            {t('platform::admin_stores.save')}
                        </Button>
                    </div>
                </form>
            ) : null}
        </section>
    );
}

/** Shown, never editable: what the store is, decided when the country was opened. */
function Fixed({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-ink-muted">{label}</dt>
            <dd className="tw-figure text-ink" dir="ltr">
                {value}
            </dd>
        </div>
    );
}
