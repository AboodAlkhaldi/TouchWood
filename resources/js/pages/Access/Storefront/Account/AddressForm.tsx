import { Fragment, useCallback, useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { CountryCombobox, type CountryOption } from '@/components/CountryCombobox';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type {
    AddressBookStore,
    AddressFieldRow,
    AddressFormData,
    AddressRow,
    PlaceOption,
    ServedByStore,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';
import { OTHER, type PlaceList, PlacePicker } from './PlacePicker';

/*
| F9's form for one address (frontend.md §3.6, F9 on Platform's places), apart from the address book:
| it loads only when Add Address or Edit Address is pressed, with the place pickers and the command
| list under them, so the account page stays within its budget (frontend.md §5).
*/

type Props = {
    store: AddressBookStore;
    address: AddressRow | null;
    returnTo: string | null;
    onDone: () => void;
};

/**
 * What the store's form offers - its countries and the own country's regions - read as it is opened,
 * and the form once they are here (the owner, 2026-10-29: only what is shown, when it is shown). A
 * failed read says so, with Try Again; the form never opens on a country list it does not have.
 */
export function AddressFormLoader(props: Props) {
    const t = useTranslator();
    const link = useLink();
    const [offer, setOffer] = useState<AddressFormData | 'loading' | 'failed'>('loading');
    const [attempt, setAttempt] = useState(0);
    const url = link('storefront.account.address-form', { book: props.store.storeCode });

    useEffect(() => {
        let current = true;
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? (response.json() as Promise<AddressFormData>) : Promise.reject(new Error(String(response.status)))))
            .then((body) => current && setOffer(body))
            .catch(() => current && setOffer('failed'));

        return () => {
            current = false;
        };
    }, [url, attempt]);

    if (offer === 'loading') {
        return (
            <div className="flex items-center gap-2 py-4 text-copy-14 text-ink-muted" role="status" data-test={`address-form-loading-${props.store.storeCode}`}>
                <Spinner aria-hidden="true" />
                {t('access::account.places.loading_form')}
            </div>
        );
    }

    if (offer === 'failed') {
        return (
            <Note variant="error" action={
                <Button type="button" variant="outline" size="sm" onClick={() => { setOffer('loading'); setAttempt((n) => n + 1); }}>
                    {t('access::account.places.try_again')}
                </Button>
            }>
                {t('access::account.places.form_failed')}
            </Note>
        );
    }

    return <AddressForm {...props} offer={offer} />;
}

/**
 * The form for one address, new or being changed: shadcn's card-with-form - the fields in the
 * content, Cancel and the one action in the footer (Geist's Fieldset).
 *
 * The fields under the first three are the **store's**, drawn from its format: their labels, their
 * order, whether each is required and how long it may be all come from the server, so a country
 * whose rules change needs no change here.
 *
 * Where the country chosen has a place list (amendment 76(d); frontend.md §3.6, F9 on Platform's
 * places), the format's region, city and district are pickers, together and in that order where the
 * first of them stands (§8.4 Q6): a city is chosen in a region, a district in a city, so changing one
 * clears what was under it. The server fills the picked places' names in, in the page's language.
 */
function AddressForm({ store, address, returnTo, onDone, offer }: Props & { offer: AddressFormData }) {
    const t = useTranslator();
    const link = useLink();
    const code = store.storeCode;
    const heading = `address-form-${code}-title`;
    // The list a new address opens on came with the form: the store's own country's regions.
    const lists = usePlaceLists(offer.regions.length === 0 ? {} : { [`country:${store.countryCode}`]: offer.regions });

    const form = useForm({
        store_id: store.storeId,
        address_id: address?.id ?? '',
        label: address?.label ?? '',
        recipient_name: address?.recipientName ?? '',
        is_default: address?.isDefault ?? false,
        return: returnTo ?? '',
        // A new address starts in the store's own country (Q1).
        country: address === null || address.countryCode === '' ? store.countryCode : address.countryCode,
        fields: Object.fromEntries(store.fields.map((field) => [field.key, address?.fields[field.key] ?? ''])) as Record<string, string>,
    });

    // What each picker holds: a place's id, OTHER (typed), or '' (nothing yet). A place typed beside a
    // picked one above it is "Other" (76(d)); an address typed before its country had a list starts
    // empty, with what it said under each picker (Q10).
    const [picks, setPicks] = useState(() => initialPicks(address));
    const saved = { region: address?.regionId ?? null, city: address?.cityId ?? null, district: address?.districtId ?? null };

    const countries: CountryOption[] = offer.countries.map((country) => ({
        code: country.code,
        name: country.name,
        otherName: country.otherName,
        ours: false,
        reason: country.servedBy === null ? undefined : t(`access::account.places.${country.servedBy.open ? 'served_by' : 'served_by_closed'}`, { store: country.servedBy.name }),
    }));
    // A country taken off the list since keeps the address it was saved in (76(f)), named as the
    // browser names it: the page carries the names of the countries offered only.
    if (!countries.some((country) => country.code === form.data.country)) countries.push({ code: form.data.country, name: countryName(form.data.country), ours: false });

    const chosen = offer.countries.find((country) => country.code === form.data.country);
    const served = offer.countries.filter((country) => country.servedBy !== null);
    const hasList = chosen?.hasPlaces ?? false;
    const keyed = new Map(store.fields.map((field) => [field.key, field]));
    const firstPlace = store.fields.findIndex((field) => PLACE_KEYS.includes(field.key));

    const cities = isPlace(picks.region) ? lists.of(`region:${picks.region}`) : IDLE;
    const districts = isPlace(picks.city) ? lists.of(`city:${picks.city}`) : IDLE;
    // A district is typed for an "Other" city, and in a city with no district list (76(d)).
    // A region with no city listed (a country listed to its top level only): the city is typed.
    const cityNoList = cities.state === 'ready' && cities.places.length === 0 && !isPlace(picks.city);
    // A saved district stays picked though its city's list now reads empty - retired since (76(i)).
    const districtTyped =
        picks.city === OTHER || cityNoList || (districts.state === 'ready' && districts.places.length === 0 && !isPlace(picks.district));

    // The picked city's districts decide whether the district is picked or typed: read at once.
    const { load } = lists;
    useEffect(() => {
        if (hasList && isPlace(picks.city)) load(`city:${picks.city}`, { city: picks.city });
    }, [hasList, picks.city, load]);
    // And the picked region's cities, which decide whether the city is picked or typed.
    useEffect(() => {
        if (hasList && isPlace(picks.region)) load(`region:${picks.region}`, { region: picks.region });
    }, [hasList, picks.region, load]);

    const setField = (key: string, value: string) => form.setData('fields', { ...form.data.fields, [key]: value });
    const clearFields = (keys: string[]) => form.setData('fields', { ...form.data.fields, ...Object.fromEntries(keys.map((key) => [key, ''])) });

    const pick = (level: 'region' | 'city' | 'district', value: string) => {
        if (picks[level] === value) return;
        if (level === 'region') {
            setPicks({ region: value, city: '', district: '' });
            clearFields(['city', 'district']);
        } else if (level === 'city') {
            setPicks({ ...picks, city: value, district: '' });
            clearFields(value === OTHER ? ['district'] : ['city', 'district']);
        } else {
            setPicks({ ...picks, district: value });
            if (value !== OTHER) clearFields(['district']);
        }
    };

    const fieldBox = (field: AddressFieldRow, id = `${code}-${field.key}`) => ({
        id,
        label: field.label,
        value: form.data.fields[field.key] ?? '',
        rules: { required: field.required, length: { max: field.maxLength } },
    });

    // Each box as typed (frontend.md §1.7), with the server's rules: a name of up to 50 characters
    // (Address::LABEL_MAX), a recipient of up to 100 (Address::RECIPIENT_MAX), and each of the store's
    // own fields required or not and as long as its format says (StoreAddressFormat::accept). The
    // format's limit on all its fields together (StoreAddressFormat::VALUES_MAX) is the server's alone. Where the country has a list,
    // the region is always asked for (AddressPlaceRules), the city and district as the format says.
    const checks = useChecks([
        { id: `label-${code}`, label: t('access::account.address_label'), value: form.data.label, rules: { required: true, length: { max: 50 } } },
        { id: `recipient-${code}`, label: t('access::account.recipient_name'), value: form.data.recipient_name, rules: { required: true, length: { max: 100 } } },
        ...store.fields.flatMap((field) => {
            if (!hasList || !PLACE_KEYS.includes(field.key)) return [fieldBox(field)];
            if (field.key === 'administrative_area') return [{ id: `${code}-region-pick`, label: field.label, value: picks.region, rules: { required: true } }];
            if (field.key === 'city') {
                if (cityNoList) return [fieldBox(field)];

                return [
                    { id: `${code}-city-pick`, label: field.label, value: picks.city, rules: { required: field.required }, off: picks.region === '' },
                    ...(picks.city === OTHER ? [fieldBox(field)] : []),
                ];
            }
            if (districtTyped) return [fieldBox(field)];

            return [
                { id: `${code}-district-pick`, label: field.label, value: picks.district, rules: { required: field.required }, off: !isPlace(picks.city) },
                ...(picks.district === OTHER ? [fieldBox(field)] : []),
            ];
        }),
    ]);

    const errors = form.errors as Record<string, string | undefined>;
    const refusal = (key: string) => errors[key] ?? errors[`fields.${key}`];
    // A country another store serves is never saved here (76(c)): its note says where it belongs.
    const servedReason = chosen?.servedBy ? servedNote(t, chosen.name, chosen.servedBy) : undefined;

    const textField = (field: AddressFieldRow, helper?: string) => (
        <TextField
            key={field.key}
            id={`${code}-${field.key}`}
            name={`fields[${field.key}]`}
            label={field.label}
            helper={helper}
            check={checks.box(`${code}-${field.key}`, refusal(field.key))}
            required={field.required}
            value={form.data.fields[field.key] ?? ''}
            onChange={(event) => setField(field.key, event.target.value)}
        />
    );

    // "You wrote: Jeddah" under an empty picker, on an address typed before its country had a list (Q10).
    const wrote = (key: string, value: string) =>
        address?.needsPlaces && value === '' && (address.fields[key] ?? '') !== '' ? t('access::account.places.you_wrote', { text: address.fields[key] ?? '' }) : undefined;

    const places = () => {
        const region = keyed.get('administrative_area');
        const city = keyed.get('city');
        const district = keyed.get('district');

        return (
            <Fragment key="places">
                {region ? (
                    <PlacePicker
                        id={`${code}-region-pick`}
                        label={region.label}
                        list={lists.of(`country:${form.data.country}`)}
                        value={picks.region}
                        shownName={picks.region !== '' && picks.region === saved.region ? address?.fields.administrative_area : undefined}
                        retired={address?.retiredPlaces.includes(picks.region) ?? false}
                        other={false}
                        helper={wrote('administrative_area', picks.region)}
                        check={checks.box(`${code}-region-pick`, refusal('administrative_area'))}
                        onOpen={() => lists.load(`country:${form.data.country}`, { country: form.data.country })}
                        onPick={(value) => pick('region', value)}
                    />
                ) : null}
                {city ? (
                    <>
                        {cityNoList ? null : (
                            <PlacePicker
                                id={`${code}-city-pick`}
                                label={city.label}
                                list={cities}
                                value={picks.city}
                                shownName={isPlace(picks.city) && picks.city === saved.city ? address?.fields.city : undefined}
                                retired={address?.retiredPlaces.includes(picks.city) ?? false}
                                other
                                disabledReason={picks.region === '' ? t('access::account.places.region_first') : undefined}
                                helper={wrote('city', picks.city)}
                                check={checks.box(`${code}-city-pick`, picks.city === OTHER ? undefined : refusal('city'))}
                                onOpen={() => lists.load(`region:${picks.region}`, { region: picks.region })}
                                onPick={(value) => pick('city', value)}
                            />
                        )}
                        {cityNoList || picks.city === OTHER ? textField(city, cityNoList ? undefined : t('access::account.places.other_hint')) : null}
                    </>
                ) : null}
                {/* The picker and the typed box keep their places, so the box is not drawn anew -
                    and focus lost - when the city's list arrives empty (the review of this job). */}
                {district ? (
                    <>
                        {districtTyped ? null : (
                            <PlacePicker
                                id={`${code}-district-pick`}
                                label={district.label}
                                list={districts}
                                value={picks.district}
                                shownName={isPlace(picks.district) && picks.district === saved.district ? address?.fields.district : undefined}
                                retired={address?.retiredPlaces.includes(picks.district) ?? false}
                                other
                                disabledReason={isPlace(picks.city) ? undefined : t('access::account.places.city_first')}
                                helper={wrote('district', picks.district)}
                                check={checks.box(`${code}-district-pick`, picks.district === OTHER ? undefined : refusal('district'))}
                                onOpen={() => lists.load(`city:${picks.city}`, { city: picks.city })}
                                onPick={(value) => pick('district', value)}
                            />
                        )}
                        {districtTyped || picks.district === OTHER ? textField(district, districtTyped ? undefined : t('access::account.places.other_hint')) : null}
                    </>
                ) : null}
            </Fragment>
        );
    };

    return (
        <Card className="material-base gap-0 border-0 py-0">
            <form
                aria-labelledby={heading}
                onSubmit={(event) => {
                    event.preventDefault();
                    if (servedReason !== undefined) return;
                    checks.submit(() => {
                        // The ids picked, each empty where typed (76(e)). A country that lost its list
                        // or left the list since keeps the places the address had, as Access does
                        // while they are left as they were (AddressPlaceRules).
                        const kept = !hasList && address !== null && form.data.country === address.countryCode;
                        form.transform((data) => ({
                            ...data,
                            region_id: kept ? (address.regionId ?? '') : hasList && isPlace(picks.region) ? picks.region : '',
                            city_id: kept ? (address.cityId ?? '') : hasList && isPlace(picks.city) ? picks.city : '',
                            district_id: kept ? (address.districtId ?? '') : hasList && !districtTyped && isPlace(picks.district) ? picks.district : '',
                        }));
                        form.post(link('storefront.account.addresses.save'), {
                            preserveScroll: true,
                            onSuccess: onDone,
                        });
                    });
                }}
                data-test={`address-form-${code}`}
            >
                <CardHeader className="px-6 pt-5 pb-4">
                    <CardTitle className="text-heading-16 text-ink">
                        {/* Which address is being changed, by its name: the form opens under the
                            list, away from the row it came from. */}
                        <h3 id={heading}>{address === null ? t('access::account.add_address') : `${t('access::account.edit_address')}: ${address.label}`}</h3>
                    </CardTitle>
                </CardHeader>

                <CardContent className="px-6 pb-5">
                    <FieldGroup className="gap-5">
                        <FormError />

                        {/* Focus goes to the form that just opened: the button that opened it may be
                            gone (Add Address), or far above it (Edit Address) (the review of batch C). */}
                        <TextField
                            id={`label-${code}`}
                            autoFocus
                            label={t('access::account.address_label')}
                            helper={t('access::account.address_label_hint')}
                            check={checks.box(`label-${code}`, form.errors.label)}
                            required
                            value={form.data.label}
                            onChange={(event) => form.setData('label', event.target.value)}
                        />

                        {/* No phone here: delivery uses the account's verified phone (access.md amendment 89). */}
                        <TextField
                            id={`recipient-${code}`}
                            label={t('access::account.recipient_name')}
                            check={checks.box(`recipient-${code}`, form.errors.recipient_name)}
                            required
                            value={form.data.recipient_name}
                            onChange={(event) => form.setData('recipient_name', event.target.value)}
                        />

                        {/* The country: Platform's address countries, the store's own first (Q1). One
                            another store serves stays in the list out of reach, and says where it
                            belongs, with the way there (Q2). Changing it clears the places. */}
                        <div className="grid gap-2">
                            <CountryCombobox
                                id={`${code}-country`}
                                label={t('access::account.places.country')}
                                countries={countries}
                                value={form.data.country}
                                error={errors.country}
                                words={{ search: t('access::account.places.search_country'), none: (query) => t('access::account.places.no_country', { query }) }}
                                onChange={(country) => {
                                    if (country === form.data.country) return;
                                    form.setData((data) => ({ ...data, country, fields: { ...data.fields, ...Object.fromEntries(PLACE_KEYS.map((key) => [key, ''])) } }));
                                    setPicks({ region: '', city: '', district: '' });
                                }}
                            />
                            {/* A list item cannot hold a link, so where each served country belongs is
                                said under the picker, with the way to that store's addresses; a
                                closed store has no part on this tab, so no link (shipping.md §1.2). */}
                            {servedReason !== undefined && chosen?.servedBy ? (
                                <Note variant="warning" size="small" data-test={`${code}-served`}>
                                    {servedReason} <ServedLink store={chosen.servedBy} />
                                </Note>
                            ) : (
                                served.map((country) =>
                                    country.servedBy === null ? null : (
                                        <p key={country.code} className="text-copy-13 text-ink-muted" data-test={`${code}-served-${country.code}`}>
                                            {servedNote(t, country.name, country.servedBy)} <ServedLink store={country.servedBy} />
                                        </p>
                                    ),
                                )
                            )}
                        </div>

                        {store.fields.map((field, index) => {
                            if (!hasList || !PLACE_KEYS.includes(field.key)) return textField(field);

                            // The three pickers together, where the format's first of them stands.
                            return index === firstPlace ? places() : null;
                        })}

                        {/* The first address in a store becomes its default on its own, so this is
                            only ever a way to move the flag - never a way to leave a store without
                            one. A checkbox, not Geist's Toggle: a Toggle is for a setting where ON
                            takes effect immediately, and this means nothing until the form is saved. */}
                        <Field orientation="horizontal">
                            <Checkbox
                                id={`default-${code}`}
                                checked={form.data.is_default}
                                onCheckedChange={(checked) => form.setData('is_default', checked === true)}
                                className="border-ink-subtle"
                            />
                            <FieldLabel htmlFor={`default-${code}`} className="text-label-14 font-normal text-ink">
                                {t('access::account.default_address')}
                            </FieldLabel>
                        </Field>
                    </FieldGroup>
                </CardContent>

                <CardFooter className="justify-end gap-2 border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                    <Button type="button" variant="ghost" onClick={onDone} data-test={`cancel-address-${code}`}>
                        {t('access::account.cancel')}
                    </Button>
                    <ActionButton type="submit" loading={form.processing} disabledReason={servedReason ?? checks.reason} data-test={`save-address-${code}`}>
                        {t('access::account.save')}
                    </ActionButton>
                </CardFooter>
            </form>
        </Card>
    );
}

function servedNote(t: ReturnType<typeof useTranslator>, country: string, store: ServedByStore): string {
    return t(`access::account.places.${store.open ? 'served_note' : 'served_note_closed'}`, { country, store: store.name });
}

function ServedLink({ store }: { store: ServedByStore }) {
    const t = useTranslator();

    return store.open ? (
        <a href={`#addresses-${store.code}`} className="underline underline-offset-4">
            {t('access::account.places.served_link', { store: store.name })}
        </a>
    ) : null;
}

/** A country's name in the page's language, for one the page was not given (76(f)). */
function countryName(code: string): string {
    try {
        return new Intl.DisplayNames([document.documentElement.lang || 'en'], { type: 'region' }).of(code) ?? code;
    } catch {
        return code;
    }
}

/** The format keys a place list fills (AddressPlaceRules). */
const PLACE_KEYS = ['administrative_area', 'city', 'district'];

const IDLE: PlaceList = { state: 'idle' };

function isPlace(value: string): boolean {
    return value !== '' && value !== OTHER;
}

function initialPicks(address: AddressRow | null): { region: string; city: string; district: string } {
    if (address === null || address.regionId === null) return { region: '', city: '', district: '' };
    const typed = (key: string) => ((address.fields[key] ?? '') !== '' ? OTHER : '');

    return {
        region: address.regionId,
        city: address.cityId ?? typed('city'),
        district: address.cityId === null ? '' : (address.districtId ?? typed('district')),
    };
}

/**
 * The place lists read so far, by key ("country:SA", "region:<id>", "city:<id>"), each read once
 * (§8.4 Q7); one that failed is read again when asked for again (Try Again).
 */
function usePlaceLists(given: Record<string, PlaceOption[]>) {
    const link = useLink();
    const linked = useRef(link);
    const [lists, setLists] = useState<Record<string, PlaceList>>(() =>
        Object.fromEntries(Object.entries(given).map(([key, places]): [string, PlaceList] => [key, { state: 'ready', places }])),
    );
    // Asked for and not failed: a failed list is asked for again when its picker is opened again.
    const asked = useRef(new Set<string>(Object.keys(given)));

    useEffect(() => {
        linked.current = link;
    });

    const load = useCallback((key: string, query: Record<string, string>) => {
        if (asked.current.has(key)) return;
        asked.current.add(key);
        setLists((current) => ({ ...current, [key]: { state: 'loading' } }));
        fetch(linked.current('storefront.account.places', query), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? (response.json() as Promise<{ places: PlaceOption[] }>) : Promise.reject(new Error(String(response.status)))))
            .then((body) => setLists((current) => ({ ...current, [key]: { state: 'ready', places: body.places } })))
            .catch(() => {
                asked.current.delete(key);
                setLists((current) => ({ ...current, [key]: { state: 'failed' } }));
            });
    }, []);

    return { load, of: (key: string): PlaceList => lists[key] ?? IDLE };
}
