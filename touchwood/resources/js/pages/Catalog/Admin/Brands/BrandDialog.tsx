import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { CountryCombobox } from '@/components/CountryCombobox';
import { SelectField, TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { BrandData, CountryOptionData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { ImageField } from '../ImageField';
import { marksLength } from '../marks';
import { MarksField } from '../parts';
import { SLUG_RULES, slugsWrong } from '../slugs';

/*
| The brand's add and edit dialog (catalog.md §1.6, §4.4 S1): a module of its own, loaded when the
| brands screen first opens it, so the page itself stays within its budget (frontend.md §5).
*/

const AGENCIES = ['HOUSE', 'EXCLUSIVE_AGENT', 'DISTRIBUTOR'] as const;

type BrandForm = {
    name_ar: string;
    name_en: string;
    slug_ar: string;
    slug_en: string;
    agency_type: string;
    show_in_default_listings: boolean;
    origin_country: string;
    description_ar: string;
    description_en: string;
    logo_media_id: string;
    logo: File | null;
    remove_logo: boolean;
};

function initial(brand: BrandData | null): BrandForm {
    return {
        name_ar: brand?.nameAr ?? '',
        name_en: brand?.nameEn ?? '',
        slug_ar: brand?.slugAr ?? '',
        slug_en: brand?.slugEn ?? '',
        agency_type: brand?.agencyType ?? 'DISTRIBUTOR',
        show_in_default_listings: brand?.showInDefaultListings ?? true,
        origin_country: brand?.originCountry ?? '',
        description_ar: brand?.descriptionAr ?? '',
        description_en: brand?.descriptionEn ?? '',
        logo_media_id: brand?.logoMediaId ?? '',
        logo: null,
        remove_logo: false,
    };
}

/** Add a brand, or edit one: the whole form, as the handler takes it. */
export function BrandDialog({
    brand,
    countries,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    brand: BrandData | null;
    countries: CountryOptionData[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<BrandForm>(initial(brand));
    const [addresses, setAddresses] = useState(false);
    // A refused address is never left folded away out of sight.
    const addressRefused = form.errors.slug_ar !== undefined || form.errors.slug_en !== undefined;

    useEffect(() => {
        if (open) {
            form.setDefaults(initial(brand));
            form.reset();
            form.clearErrors();
            setAddresses(false);
        }
    }, [open, brand?.id]);

    const title = brand === null ? t('catalog::admin_brands.add') : t('catalog::admin_brands.edit_title');
    // Each box as typed (frontend.md §1.7), with the domain's rules: names of up to 100 characters
    // (Brand::NAME_MAX, LocalizedName), descriptions of up to 5,000 characters counted
    // as StructuredText counts them (Brand::DESCRIPTION_MAX), and the web addresses (SLUG_RULES) -
    // whose section stays open while one is wrong. A description's "both languages or neither" is
    // left to the server.
    const name = { required: true, length: { max: 100 } };
    const description = { length: { max: 5000, of: marksLength } };
    const checks = useChecks([
        { id: 'brand-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name },
        { id: 'brand-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name },
        { id: 'brand-description-ar', label: t('catalog::admin_brands.field.description_ar'), value: form.data.description_ar, rules: description },
        { id: 'brand-description-en', label: t('catalog::admin_brands.field.description_en'), value: form.data.description_en, rules: description },
        { id: 'brand-slug-ar', label: t('catalog::admin.field.slug_ar'), value: form.data.slug_ar, rules: SLUG_RULES.ar },
        { id: 'brand-slug-en', label: t('catalog::admin.field.slug_en'), value: form.data.slug_en, rules: SLUG_RULES.en },
    ]);

    function submit() {
        // Sent as multipart only when a logo file is attached (Inertia does that by itself).
        checks.submit(() =>
            form.post(brand === null ? '/admin/brands' : `/admin/brands/${brand.id}`, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            }),
        );
    }

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={brand === null ? t('catalog::admin_brands.add_body') : t('catalog::admin_brands.edit_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-brand">
                    {brand === null ? t('catalog::admin_brands.add') : t('catalog::admin_brands.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField id="brand-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} check={checks.box('brand-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="brand-name-ar" />
                    <TextField id="brand-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} value={form.data.name_en} check={checks.box('brand-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="brand-name-en" />
                </div>

                <SelectField id="brand-agency" label={t('catalog::admin_brands.field.agency')} value={form.data.agency_type} error={form.errors.agency_type} onChange={(event) => form.setData('agency_type', event.target.value)} data-test="brand-agency">
                    {AGENCIES.map((agency) => (
                        <NativeSelectOption key={agency} value={agency}>
                            {t(`catalog::admin_brands.agency.${agency}`)}
                        </NativeSelectOption>
                    ))}
                </SelectField>

                {/* One setting on its own is a switch, its sentence tied to it (Geist's Toggle). */}
                <Field orientation="horizontal">
                    <FieldContent>
                        <FieldLabel htmlFor="brand-listings" className="text-label-14 text-ink">
                            {t('catalog::admin_brands.field.listings')}
                        </FieldLabel>
                        <FieldDescription id="brand-listings-helper" className="text-copy-13 text-ink-muted">
                            {t('catalog::admin_brands.field.listings_helper')}
                        </FieldDescription>
                    </FieldContent>
                    <Switch
                        id="brand-listings"
                        checked={form.data.show_in_default_listings}
                        onCheckedChange={(on) => form.setData('show_in_default_listings', on)}
                        aria-describedby="brand-listings-helper"
                        className="data-[state=unchecked]:bg-ink-subtle"
                        data-test="brand-listings"
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <CountryCombobox
                        id="brand-country"
                        label={t('catalog::admin_brands.field.country')}
                        countries={countries}
                        value={form.data.origin_country}
                        onChange={(code) => form.setData('origin_country', code)}
                        error={form.errors.origin_country}
                        words={{
                            search: t('catalog::admin_brands.country.search'),
                            none: (query) => t('catalog::admin_brands.country.none', { query }),
                        }}
                    />
                    {/* Optional: a country chosen can be taken back (amendment 1(j)). */}
                    {form.data.origin_country !== '' ? (
                        <Button type="button" variant="ghost" size="sm" className="self-end justify-self-start" onClick={() => form.setData('origin_country', '')} data-test="brand-country-clear">
                            {t('catalog::admin_brands.country.clear')}
                        </Button>
                    ) : null}
                </div>

                <ImageField
                    id="brand-logo"
                    label={t('catalog::admin_brands.field.logo')}
                    held={(brand?.logoMediaId ?? null) !== null}
                    current={brand?.logo ?? null}
                    onFile={(file) => form.setData('logo', file)}
                    remove={form.data.remove_logo}
                    onRemove={(remove) => form.setData('remove_logo', remove)}
                    error={form.errors.logo_media_id}
                />

                <MarksField id="brand-description-ar" dir="rtl" label={t('catalog::admin_brands.field.description_ar')} value={form.data.description_ar} check={checks.box('brand-description-ar', form.errors.description_ar)} onChange={(value) => form.setData('description_ar', value)} />
                <MarksField id="brand-description-en" dir="ltr" label={t('catalog::admin_brands.field.description_en')} value={form.data.description_en} check={checks.box('brand-description-en', form.errors.description_en)} onChange={(value) => form.setData('description_en', value)} />

                <Collapsible open={addresses || addressRefused || slugsWrong(form.data.slug_ar, form.data.slug_en)} onOpenChange={setAddresses}>
                    <CollapsibleTrigger asChild>
                        <Button type="button" variant="ghost" size="sm" className="justify-start px-0" data-test="brand-addresses">
                            {t('catalog::admin.addresses.title')}
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent className="grid gap-4 pt-2 sm:grid-cols-2">
                        <TextField id="brand-slug-ar" dir="rtl" label={t('catalog::admin.field.slug_ar')} helper={t('catalog::admin.addresses.helper')} value={form.data.slug_ar} check={checks.box('brand-slug-ar', form.errors.slug_ar)} onChange={(event) => form.setData('slug_ar', event.target.value)} />
                        <TextField id="brand-slug-en" dir="ltr" label={t('catalog::admin.field.slug_en')} helper={t('catalog::admin.addresses.helper')} value={form.data.slug_en} check={checks.box('brand-slug-en', form.errors.slug_en)} onChange={(event) => form.setData('slug_en', event.target.value)} />
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </PanelDialog>
    );
}
