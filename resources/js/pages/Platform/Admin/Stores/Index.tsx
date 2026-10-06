import { useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { CountryCombobox } from '@/components/CountryCombobox';
import { SelectField, TextField } from '@/components/Fields';
import { DialogError, FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { SearchCombobox } from '@/components/SearchCombobox';
import { Description } from '@/components/geist-only/Description';
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
import { Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { FieldDescription, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useReturnFocus } from '@/lib/use-return-focus';
import type { NewStoreForm, StoreRow, StoresPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| E1 - the stores, with E2 as the form on each card (frontend.md §3.5), each store one of shadcn's
| Cards in Geist's Fieldset form (§1.11).
|
| Only the stores this person may see are here: Platform's read model answered that, store by
| store, and the edit form appears only on the ones they may change. Offering is not allowing - the
| handler behind the form asks again, against that one store.
|
| A Super Admin adds a store here too (platform.md §9.7 #3; owner, 2026-10-06, replacing "a console
| command only"): Add Store takes everything at once, so a store is still never half-configured.
|
| The code, the country and the currency are shown and cannot be changed: they are what the store
| *is*. Platform refuses an attempt to change them rather than ignoring it, and the card says so -
| as Geist's Description, with the sentence that explains them in the footer by the save button, as
| Geist's Fieldset lays a section out. The form opens in shadcn's Collapsible, so the button that
| opens it says whether it is open (aria-expanded).
|
| The on/off switch (platform.md §1.1, §9.5; owner, 2026-10-01) is a Super Admin's alone, so only
| they see a store's state and an off store at all. Turning one on just posts; turning one off is
| asked first, in an AlertDialog whose button and toast share the verb ("Turn Store Off" → "Store
| turned off"). The base store is never off: its button stays, out of reach, saying why. One badge
| per row (Geist's Badge): the state; "Base Store" is said in the line under the name.
*/

type Props = StoresPage;

export default function Index({ stores, timezones, maySwitch, add }: Props) {
    const t = useTranslator();
    const [editing, setEditing] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);

    return (
        <AdminLayout
            title={t('platform::admin_stores.title')}
            subtitle={t('platform::admin_stores.subtitle')}
            action={
                // Add Store, for whoever may open one (a Super Admin); the form ends with its own
                // Cancel, so the button steps out while it is open (platform.md §9.7 #3).
                add !== null && !adding ? (
                    <Button type="button" data-test="add-store" onClick={() => setAdding(true)}>
                        {t('platform::admin_stores.add')}
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4">
                <FormError />

                {add !== null && adding ? <AddStoreForm add={add} timezones={timezones} onDone={() => setAdding(false)} /> : null}

                {stores.length === 0 ? (
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('platform::admin_stores.none_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('platform::admin_stores.no_stores')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    stores.map((store) => (
                        <StoreCard
                            key={store.id}
                            store={store}
                            maySwitch={maySwitch}
                            timezones={timezones}
                            open={editing === store.id}
                            onOpenChange={(open) => setEditing(open ? store.id : null)}
                        />
                    ))
                )}
            </div>
        </AdminLayout>
    );
}

/** The option that opens the new currency's fields instead of picking a free one. */
const NEW_CURRENCY = '__new';

/*
| Add Store (platform.md §9.7 #3, #4; owner, 2026-10-06): everything a store is, at once, so it is
| never half-configured; it is added switched off. The currency is never typed: it is picked from the
| currencies no store uses - one currency, one store - or made here, in the same form, when "New
| Currency…" is picked or when none is free. The country is the shared country picker; choosing one
| fills its first time zone, which stays changeable.
*/
function AddStoreForm({ add, timezones, onDone }: { add: NewStoreForm; timezones: string[]; onDone: () => void }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const noneFree = add.freeCurrencies.length === 0;

    const form = useForm({
        code: '',
        name_ar: '',
        name_en: '',
        country: '',
        currency: add.freeCurrencies[0]?.code ?? '',
        tax_rate: '',
        timezone: '',
        position: String(add.nextPosition),
        new_currency: noneFree,
        currency_code: '',
        currency_exponent: '2',
        currency_name_ar: '',
        currency_name_en: '',
        currency_abbreviation_ar: '',
        currency_abbreviation_en: '',
        currency_sign: '',
    });

    return (
        <Card className="material-base gap-0 border-0 py-0" data-test="add-store-form">
            <form
                aria-labelledby="add-store-title"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/admin/stores', { preserveScroll: true, onSuccess: onDone });
                }}
            >
                <CardHeader className="px-5 pt-5 pb-4">
                    <CardTitle>
                        <h2 id="add-store-title" className="text-heading-16 text-ink">
                            {t('platform::admin_stores.add')}
                        </h2>
                    </CardTitle>
                    <CardDescription className="text-copy-13 text-ink-muted">{t('platform::admin_stores.starts_off')}</CardDescription>
                </CardHeader>

                <CardContent className="grid gap-5 px-5 pb-5 sm:grid-cols-2">
                    <TextField
                        id="new-store-code"
                        label={t('platform::admin_stores.code')}
                        helper={t('platform::admin_stores.code_hint')}
                        required
                        dir="ltr"
                        maxLength={8}
                        inputClassName="tw-figure"
                        value={form.data.code}
                        // Lower case as it is typed: it is the shop's address.
                        onChange={(event) => form.setData('code', event.target.value.toLowerCase())}
                    />
                    <TextField
                        id="new-store-position"
                        label={t('platform::admin_stores.position')}
                        helper={t('platform::admin_stores.position_hint')}
                        required
                        dir="ltr"
                        inputMode="numeric"
                        inputClassName="tw-figure"
                        value={form.data.position}
                        onChange={(event) => form.setData('position', toLatinDigits(event.target.value))}
                    />
                    <TextField
                        id="new-store-name_ar"
                        label={t('platform::admin_stores.name_ar')}
                        required
                        lang="ar"
                        dir="rtl"
                        value={form.data.name_ar}
                        onChange={(event) => form.setData('name_ar', event.target.value)}
                    />
                    <TextField
                        id="new-store-name_en"
                        label={t('platform::admin_stores.name_en')}
                        required
                        lang="en"
                        dir="ltr"
                        value={form.data.name_en}
                        onChange={(event) => form.setData('name_en', event.target.value)}
                    />
                    <CountryCombobox
                        id="new-store-country"
                        label={t('platform::admin_stores.country')}
                        countries={add.countries}
                        value={form.data.country}
                        onChange={(country) => {
                            form.setData((data) => ({ ...data, country, timezone: add.zones[country] ?? data.timezone }));
                        }}
                        words={{
                            search: t('platform::admin_stores.country_search'),
                            none: (query) => t('platform::admin_stores.country_none', { query }),
                            ours: t('platform::admin_stores.countries_ours'),
                            all: t('platform::admin_stores.countries_all'),
                        }}
                    />
                    <TextField
                        id="new-store-tax_rate"
                        label={t('platform::admin_stores.tax_rate')}
                        helper={t('platform::admin_stores.tax_rate_hint')}
                        required
                        dir="ltr"
                        inputMode="decimal"
                        inputClassName="tw-figure"
                        value={form.data.tax_rate}
                        onChange={(event) => form.setData('tax_rate', toLatinDigits(event.target.value))}
                    />
                    <SearchCombobox
                        id="new-store-timezone"
                        label={t('platform::admin_stores.timezone')}
                        options={timezones.map((zone) => ({ value: zone, label: zone }))}
                        value={form.data.timezone}
                        onChange={(zone) => form.setData('timezone', zone)}
                        words={{
                            search: t('platform::admin_stores.timezone_search'),
                            none: (query) => t('platform::admin_stores.timezone_none', { query }),
                        }}
                        ltr
                        className="sm:col-span-2"
                    />

                    {noneFree ? null : (
                        <SelectField
                            id="new-store-currency"
                            label={t('platform::admin_stores.currency')}
                            helper={t('platform::admin_stores.currency_hint')}
                            value={form.data.new_currency ? NEW_CURRENCY : form.data.currency}
                            onChange={(event) =>
                                event.target.value === NEW_CURRENCY
                                    ? form.setData('new_currency', true)
                                    : form.setData((data) => ({ ...data, currency: event.target.value, new_currency: false }))
                            }
                            className="sm:col-span-2"
                            data-test="new-store-currency"
                        >
                            {add.freeCurrencies.map((currency) => (
                                <NativeSelectOption key={currency.code} value={currency.code}>
                                    {`${currency.code} · ${currency.name}`}
                                </NativeSelectOption>
                            ))}
                            <NativeSelectOption value={NEW_CURRENCY}>{t('platform::admin_stores.currency_new_option')}</NativeSelectOption>
                        </SelectField>
                    )}

                    {form.data.new_currency ? (
                        <FieldSet className="gap-4 rounded-md border border-line p-4 sm:col-span-2" data-test="new-store-new-currency">
                            <FieldLegend className="mb-0 text-label-14 text-ink">{t('platform::admin_stores.currency_new')}</FieldLegend>
                            <FieldDescription className="text-copy-13 text-ink-muted">
                                {noneFree ? `${t('platform::admin_stores.currency_none_free')} ${t('platform::admin_stores.currency_new_hint')}` : t('platform::admin_stores.currency_new_hint')}
                            </FieldDescription>
                            <div className="grid gap-5 sm:grid-cols-2">
                                <TextField
                                    id="new-currency-code"
                                    label={t('platform::admin_currencies.code')}
                                    helper={t('platform::admin_currencies.code_hint')}
                                    required
                                    dir="ltr"
                                    maxLength={3}
                                    inputClassName="tw-figure"
                                    value={form.data.currency_code}
                                    onChange={(event) => form.setData('currency_code', event.target.value.toUpperCase())}
                                />
                                <SelectField
                                    id="new-currency-exponent"
                                    label={t('platform::admin_currencies.exponent')}
                                    helper={t('platform::admin_currencies.exponent_hint', { two: figure(locale, 2), zero: figure(locale, 0) })}
                                    value={form.data.currency_exponent}
                                    onChange={(event) => form.setData('currency_exponent', toLatinDigits(event.target.value))}
                                >
                                    {add.exponents.map((places) => (
                                        <NativeSelectOption key={places} value={String(places)}>
                                            {figure(locale, places)}
                                        </NativeSelectOption>
                                    ))}
                                </SelectField>
                                <TextField
                                    id="new-currency-name_ar"
                                    label={t('platform::admin_currencies.name_ar')}
                                    required
                                    lang="ar"
                                    dir="rtl"
                                    value={form.data.currency_name_ar}
                                    onChange={(event) => form.setData('currency_name_ar', event.target.value)}
                                />
                                <TextField
                                    id="new-currency-name_en"
                                    label={t('platform::admin_currencies.name_en')}
                                    required
                                    lang="en"
                                    dir="ltr"
                                    value={form.data.currency_name_en}
                                    onChange={(event) => form.setData('currency_name_en', event.target.value)}
                                />
                                <TextField
                                    id="new-currency-abbreviation_ar"
                                    label={t('platform::admin_currencies.abbreviation_ar')}
                                    helper={t('platform::admin_currencies.abbreviation_hint')}
                                    required
                                    lang="ar"
                                    dir="rtl"
                                    value={form.data.currency_abbreviation_ar}
                                    onChange={(event) => form.setData('currency_abbreviation_ar', event.target.value)}
                                />
                                <TextField
                                    id="new-currency-abbreviation_en"
                                    label={t('platform::admin_currencies.abbreviation_en')}
                                    helper={t('platform::admin_currencies.abbreviation_hint')}
                                    required
                                    lang="en"
                                    dir="ltr"
                                    value={form.data.currency_abbreviation_en}
                                    onChange={(event) => form.setData('currency_abbreviation_en', event.target.value)}
                                />
                                <TextField
                                    id="new-currency-sign"
                                    label={t('platform::admin_currencies.sign')}
                                    helper={t('platform::admin_currencies.sign_hint')}
                                    value={form.data.currency_sign}
                                    onChange={(event) => form.setData('currency_sign', event.target.value)}
                                />
                            </div>
                        </FieldSet>
                    ) : null}
                </CardContent>

                <CardFooter className="justify-end gap-2 border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3">
                    <Button type="button" variant="outline" disabled={form.processing} data-test="cancel-add-store" onClick={onDone}>
                        {t('platform::admin_stores.cancel')}
                    </Button>
                    <ActionButton type="submit" loading={form.processing} data-test="create-store">
                        {t('platform::admin_stores.create')}
                    </ActionButton>
                </CardFooter>
            </form>
        </Card>
    );
}

type CardProps = {
    store: StoreRow;
    maySwitch: boolean;
    timezones: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function StoreCard({ store, maySwitch, timezones, open, onOpenChange }: CardProps) {
    const t = useTranslator();
    const [confirmingOff, setConfirmingOff] = useState(false);
    const [switching, setSwitching] = useState(false);
    // The on/off button in the card: once the store is off, Turn Off is replaced by Turn On, which
    // takes focus back (the review of batch D).
    const onOff = useRef<HTMLButtonElement>(null);
    const returnFocus = useReturnFocus(confirmingOff, onOff);
    const baseReason = store.switchable ? undefined : t('platform::admin_stores.base_hint');

    const turn = (way: 'activate' | 'deactivate', onSuccess?: () => void) =>
        router.post(
            `/admin/stores/${store.code}/${way}`,
            {},
            {
                preserveScroll: true,
                onStart: () => setSwitching(true),
                onSuccess,
                onFinish: () => setSwitching(false),
            },
        );

    const form = useForm({
        name_ar: store.nameAr,
        name_en: store.nameEn,
        tax_rate: store.taxRatePercent,
        timezone: store.timezone,
        position: String(store.position),
    });

    return (
        <Collapsible open={open} onOpenChange={onOpenChange} asChild>
            <Card className="material-base gap-0 overflow-hidden border-0 py-0" data-test={`store-${store.code}`}>
                <CardHeader className="px-5 py-4">
                    <CardTitle className="flex flex-wrap items-center gap-2">
                        <h2 className="text-heading-16 text-ink">{store.name}</h2>
                        {/* The state is only a Super Admin's to see, as an off store is (§1.6). */}
                        {maySwitch ? (
                            <Badge className={tone(store.isActive ? 'green-subtle' : 'gray-subtle')} data-test={`state-${store.code}`}>
                                {t(store.isActive ? 'platform::admin_stores.on' : 'platform::admin_stores.off')}
                            </Badge>
                        ) : null}
                    </CardTitle>
                    <CardDescription className="text-copy-13 text-ink-muted">
                        <span className="tw-figure">{store.code.toUpperCase()}</span> · <bdi dir="ltr">{`${store.currencyCode} ${store.currencySymbol}`}</bdi> ·{' '}
                        <span className="tw-figure">{store.taxRatePercent}%</span> · <bdi dir="ltr">{store.timezone}</bdi>
                        {maySwitch && store.isBase ? ` · ${t('platform::admin_stores.base')}` : ''}
                    </CardDescription>
                    <CardAction className="flex flex-wrap items-center gap-2">
                        {maySwitch && !store.isActive ? (
                            <ActionButton ref={onOff} variant="outline" loading={switching} disabledReason={baseReason} data-test={`turn-on-${store.code}`} onClick={() => turn('activate')}>
                                {t('platform::admin_stores.turn_on')}
                            </ActionButton>
                        ) : null}
                        {maySwitch && store.isActive ? (
                            <ActionButton ref={onOff} variant="outline" disabledReason={baseReason} data-test={`turn-off-${store.code}`} onClick={() => setConfirmingOff(true)}>
                                {/* Its own words, ending in "…": it opens a dialog (frontend.md §1.10). */}
                                {t('platform::admin_stores.turn_off_open')}
                            </ActionButton>
                        ) : null}
                        {store.editable ? (
                            <CollapsibleTrigger asChild>
                                <Button type="button" variant="outline" data-test={`edit-${store.code}`}>
                                    {t(open ? 'platform::admin_stores.cancel' : 'platform::admin_stores.edit')}
                                </Button>
                            </CollapsibleTrigger>
                        ) : null}
                    </CardAction>
                </CardHeader>

                {maySwitch && !store.isActive ? (
                    <div className="px-5 pb-4">
                        <Note variant="secondary" size="small">
                            {t('platform::admin_stores.off_hint')}
                        </Note>
                    </div>
                ) : null}

                <AlertDialog open={confirmingOff} onOpenChange={(next) => (switching ? undefined : setConfirmingOff(next))}>
                    <AlertDialogContent onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 data-[size=default]:sm:max-w-md">
                        <div className="grid gap-4 p-6">
                            <AlertDialogHeader>
                                <AlertDialogTitle className="text-heading-20 text-ink">{t('platform::admin_stores.turn_off')}</AlertDialogTitle>
                                <AlertDialogDescription className="text-copy-14 text-ink-muted">
                                    {t('platform::admin_stores.turn_off_confirm', { name: store.name })}
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <DialogError open={confirmingOff} />
                        </div>
                        <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                            <AlertDialogCancel disabled={switching} data-test="modal-cancel">
                                {t('ui.cancel')}
                            </AlertDialogCancel>
                            <ActionButton
                                variant="destructive"
                                loading={switching}
                                data-test={`confirm-turn-off-${store.code}`}
                                onClick={() => turn('deactivate', () => setConfirmingOff(false))}
                            >
                                {t('platform::admin_stores.turn_off')}
                            </ActionButton>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                <CollapsibleContent>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`/admin/stores/${store.code}`, { onSuccess: () => onOpenChange(false) });
                        }}
                        className="border-t border-line"
                    >
                        <CardContent className="grid gap-5 p-5 sm:grid-cols-2">
                            <TextField
                                id={`${store.id}-name_ar`}
                                label={t('platform::admin_stores.name_ar')}
                                error={form.errors.name_ar}
                                required
                                lang="ar"
                                dir="rtl"
                                value={form.data.name_ar}
                                onChange={(event) => form.setData('name_ar', event.target.value)}
                            />

                            <TextField
                                id={`${store.id}-name_en`}
                                label={t('platform::admin_stores.name_en')}
                                error={form.errors.name_en}
                                required
                                lang="en"
                                dir="ltr"
                                value={form.data.name_en}
                                onChange={(event) => form.setData('name_en', event.target.value)}
                            />

                            <TextField
                                id={`${store.id}-tax_rate`}
                                label={t('platform::admin_stores.tax_rate')}
                                helper={t('platform::admin_stores.tax_rate_hint')}
                                error={form.errors.tax_rate}
                                required
                                dir="ltr"
                                inputMode="decimal"
                                inputClassName="tw-figure"
                                value={form.data.tax_rate}
                                // A rate typed on an Arabic keyboard is the same rate (frontend.md §1.8).
                                onChange={(event) => form.setData('tax_rate', toLatinDigits(event.target.value))}
                            />

                            <TextField
                                id={`${store.id}-position`}
                                label={t('platform::admin_stores.position')}
                                helper={t('platform::admin_stores.position_hint')}
                                error={form.errors.position}
                                required
                                dir="ltr"
                                inputMode="numeric"
                                inputClassName="tw-figure"
                                value={form.data.position}
                                onChange={(event) => form.setData('position', toLatinDigits(event.target.value))}
                            />

                            {/* Several hundred zones: a list to type into (Geist's Combobox), not a Select. */}
                            <SearchCombobox
                                id={`${store.id}-timezone`}
                                label={t('platform::admin_stores.timezone')}
                                options={timezones.map((zone) => ({ value: zone, label: zone }))}
                                value={form.data.timezone}
                                onChange={(zone) => form.setData('timezone', zone)}
                                words={{
                                    search: t('platform::admin_stores.timezone_search'),
                                    none: (query) => t('platform::admin_stores.timezone_none', { query }),
                                }}
                                error={form.errors.timezone}
                                ltr
                                className="sm:col-span-2"
                            />

                            {/* Shown, never editable: what the store is, decided when the country was opened. */}
                            <div className="sm:col-span-2">
                                <Description
                                    columns={3}
                                    items={[
                                        { title: t('platform::admin_stores.code'), content: <Fixed value={store.code.toUpperCase()} /> },
                                        { title: t('platform::admin_stores.country'), content: <Fixed value={store.countryCode} /> },
                                        { title: t('platform::admin_stores.currency'), content: <Fixed value={`${store.currencyCode} ${store.currencySymbol}`} /> },
                                    ]}
                                />
                            </div>
                        </CardContent>

                        <CardFooter className="flex-wrap justify-between gap-3 border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3">
                            <p className="text-copy-13 text-ink-muted">{t('platform::admin_stores.immutable')}</p>
                            <ActionButton type="submit" loading={form.processing} data-test={`save-${store.code}`}>
                                {t('platform::admin_stores.save')}
                            </ActionButton>
                        </CardFooter>
                    </form>
                </CollapsibleContent>
            </Card>
        </Collapsible>
    );
}

/** A value that is what the store is: a code, read left to right in either language. */
function Fixed({ value }: { value: string }) {
    return (
        <span className="tw-figure">
            <bdi dir="ltr">{value}</bdi>
        </span>
    );
}
