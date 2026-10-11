import { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { CountryCombobox } from '@/components/CountryCombobox';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { BrandData, BrandsPage, CountryOptionData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { FatesDialog } from '../FatesDialog';
import { marksLength } from '../marks';
import { ImageField, MarksField, MoreButton, MoreButtonOff, NameCells, NameHeads, SLUG_RULES, StateBadge, nameIn, slugsWrong, useAllStoresReason, useLocale } from '../parts';

/*
| The brands screen (catalog.md §1.6, §4.4 S1), on shadcn's parts with Geist's rules (frontend.md
| §1.11): every brand in the list's order with its fixed number (amendment 7(b)), its agency, whether
| its products show in search and shop-wide lists or only through its own category (a secondary
| brand, amendment 5(k)), how many products carry it, its state and the default mark.
|
| Every change needs `catalog.brand.manage` with All stores (§3): for anyone else every button stays
| in sight, out of reach, with that reason (P2). Deactivating gives each product its fate (FatesDialog); the default brand is
| never deactivated or deleted, and a brand any product carries is not deleted - their items stay in
| the menu, out of reach, the reason written under them.
*/

const AGENCIES = ['HOUSE', 'EXCLUSIVE_AGENT', 'DISTRIBUTOR'] as const;

type Dialog = { action: 'add' | 'edit' | 'deactivate' | 'delete'; brand: BrandData | null } | null;

export default function Index({ brands, mayChange, reached, reachedFor, countries }: BrandsPage) {
    const t = useTranslator();
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const add = () => setDialog({ action: 'add', brand: null });
    const nextPosition = brands.reduce((highest, brand) => Math.max(highest, brand.position), 0) + 10;
    const reason = useAllStoresReason(mayChange);

    return (
        <AdminLayout
            title={t('catalog::admin_brands.title')}
            subtitle={t('catalog::admin_brands.subtitle')}
            action={
                <ActionButton type="button" onClick={add} disabledReason={reason} data-test="add-brand">
                    {t('catalog::admin_brands.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />

                {brands.length === 0 ? (
                    <Empty className="material-base" data-test="brands-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_brands.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_brands.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={add} disabledReason={reason}>
                                {t('catalog::admin_brands.add')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('catalog::admin_brands.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead className="w-12">
                                        <span aria-hidden="true">#</span>
                                        <span className="sr-only">{t('catalog::admin.column.position')}</span>
                                    </TableHead>
                                    <TableHead className="w-16">{t('catalog::admin_brands.column.number')}</TableHead>
                                    <NameHeads locale={locale} />
                                    <TableHead>{t('catalog::admin_brands.column.agency')}</TableHead>
                                    <TableHead>{t('catalog::admin_brands.column.listings')}</TableHead>
                                    <TableHead className="text-end">{t('catalog::admin.column.products')}</TableHead>
                                    <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {brands.map((brand) => (
                                    <Row
                                        key={brand.id}
                                        brand={brand}
                                        reason={reason}
                                        open={(action, trigger) => {
                                            opener.current = trigger;
                                            setDialog({ action, brand });
                                        }}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit') ? (
                <BrandDialog brand={dialog.brand} nextPosition={nextPosition} countries={countries} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
            {dialog !== null && dialog.action === 'deactivate' && dialog.brand !== null ? (
                <FatesDialog
                    kind="brand"
                    target={{ id: dialog.brand.id, name: nameIn(locale, dialog.brand.nameAr, dialog.brand.nameEn) }}
                    reached={reachedFor === dialog.brand.id ? reached : null}
                    moveTargets={brands
                        .filter((brand) => brand.active && brand.id !== dialog.brand?.id)
                        .map((brand) => ({ id: brand.id, name: nameIn(locale, brand.nameAr, brand.nameEn) }))}
                    open
                    onOpenChange={close}
                    returnFocusTo={opener}
                />
            ) : null}
            {dialog !== null && dialog.action === 'delete' && dialog.brand !== null ? (
                <DeleteBrandDialog brand={dialog.brand} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
        </AdminLayout>
    );
}

function Row({ brand, reason, open }: { brand: BrandData; reason: string | undefined; open: (action: 'edit' | 'deactivate' | 'delete', trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const name = nameIn(locale, brand.nameAr, brand.nameEn);
    const post = (path: string) => router.post(`/admin/brands/${brand.id}/${path}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    // Why an item cannot be used, written under it: the default brand stays, a used brand stays.
    const deactivateReason = brand.isDefault ? t('catalog::admin_brands.reason.default') : null;
    const deleteReason = brand.isDefault ? t('catalog::admin_brands.reason.default') : brand.products > 0 ? t('catalog::admin_brands.reason.in_use') : null;

    return (
        <TableRow data-test={`brand-${brand.id}`}>
            <TableCell className="tw-figure text-ink-muted">{figure(locale, brand.position)}</TableCell>
            <TableCell className="tw-figure" data-test="brand-number">
                {figure(locale, brand.number)}
            </TableCell>
            <NameCells
                locale={locale}
                ar={brand.nameAr}
                en={brand.nameEn}
                lead={
                    <>
                        {brand.logo !== null ? <img src={brand.logo} alt="" className="size-8 rounded-md border border-line object-contain" /> : null}
                        {brand.isDefault ? (
                            <Badge className={tone('blue-subtle')} data-test="brand-default">
                                {t('catalog::admin_brands.default')}
                            </Badge>
                        ) : null}
                    </>
                }
            />
            <TableCell>{t(`catalog::admin_brands.agency.${brand.agencyType}`)}</TableCell>
            <TableCell>{brand.showInDefaultListings ? t('catalog::admin_brands.listings.everywhere') : t('catalog::admin_brands.listings.secondary')}</TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, brand.products)}</TableCell>
            <TableCell>
                <StateBadge active={brand.active} />
            </TableCell>
            <TableCell className="text-end">
                {reason !== undefined ? (
                    <MoreButtonOff name={name} reason={reason} />
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={name} busy={busy} data-test={`brand-actions-${brand.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-brand">
                                {t('catalog::admin_brands.edit')}
                            </DropdownMenuItem>
                            {brand.active && !brand.isDefault ? (
                                <DropdownMenuItem disabled={busy} onSelect={() => post('default')} data-test="default-brand">
                                    {t('catalog::admin_brands.make_default')}
                                </DropdownMenuItem>
                            ) : null}
                            {!brand.active ? (
                                <DropdownMenuItem disabled={busy} onSelect={() => post('activate')} data-test="activate-brand">
                                    {t('catalog::admin_brands.activate')}
                                </DropdownMenuItem>
                            ) : null}
                            {/* Destructive items last, after a divider (Geist's Menu). */}
                            <DropdownMenuSeparator />
                            {brand.active ? <Guarded label={t('catalog::admin_brands.deactivate')} reason={deactivateReason} onSelect={() => open('deactivate', more.current)} test="deactivate-brand" /> : null}
                            <Guarded label={t('catalog::admin_brands.delete')} reason={deleteReason} onSelect={() => open('delete', more.current)} test="delete-brand" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </TableCell>
        </TableRow>
    );
}

/** A destructive item, out of reach with its reason written under it when it cannot be done. */
function Guarded({ label, reason, onSelect, test }: { label: string; reason: string | null; onSelect: () => void; test: string }) {
    return (
        <DropdownMenuItem
            variant="destructive"
            aria-disabled={reason !== null || undefined}
            className={reason !== null ? 'cursor-not-allowed' : undefined}
            onSelect={(event) => {
                if (reason !== null) {
                    event.preventDefault();

                    return;
                }

                onSelect();
            }}
            data-test={test}
        >
            <span className="grid gap-0.5">
                <span className={reason !== null ? 'opacity-60' : undefined}>{label}</span>
                {reason !== null ? <span className="text-copy-12 text-ink-muted">{reason}</span> : null}
            </span>
        </DropdownMenuItem>
    );
}

type BrandForm = {
    name_ar: string;
    name_en: string;
    slug_ar: string;
    slug_en: string;
    agency_type: string;
    show_in_default_listings: boolean;
    position: string;
    origin_country: string;
    description_ar: string;
    description_en: string;
    logo_media_id: string;
    logo: File | null;
    remove_logo: boolean;
};

function initial(brand: BrandData | null, nextPosition: number): BrandForm {
    return {
        name_ar: brand?.nameAr ?? '',
        name_en: brand?.nameEn ?? '',
        slug_ar: brand?.slugAr ?? '',
        slug_en: brand?.slugEn ?? '',
        agency_type: brand?.agencyType ?? 'DISTRIBUTOR',
        show_in_default_listings: brand?.showInDefaultListings ?? true,
        position: String(brand?.position ?? nextPosition),
        origin_country: brand?.originCountry ?? '',
        description_ar: brand?.descriptionAr ?? '',
        description_en: brand?.descriptionEn ?? '',
        logo_media_id: brand?.logoMediaId ?? '',
        logo: null,
        remove_logo: false,
    };
}

/** Add a brand, or edit one: the whole form, as the handler takes it. */
function BrandDialog({
    brand,
    nextPosition,
    countries,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    brand: BrandData | null;
    nextPosition: number;
    countries: CountryOptionData[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<BrandForm>(initial(brand, nextPosition));
    const [addresses, setAddresses] = useState(false);
    // A refused address is never left folded away out of sight.
    const addressRefused = form.errors.slug_ar !== undefined || form.errors.slug_en !== undefined;

    useEffect(() => {
        if (open) {
            form.setDefaults(initial(brand, nextPosition));
            form.reset();
            form.clearErrors();
            setAddresses(false);
        }
    }, [open, brand?.id]);

    const title = brand === null ? t('catalog::admin_brands.add') : t('catalog::admin_brands.edit_title');
    // Each box as typed (frontend.md §1.7), with the domain's rules: names of up to 100 characters
    // (Brand::NAME_MAX, LocalizedName), a position from 0 to 10,000 (ListPosition; the request reads an
    // empty or broken number as -1, so it is required), descriptions of up to 5,000 characters counted
    // as StructuredText counts them (Brand::DESCRIPTION_MAX), and the web addresses (SLUG_RULES) -
    // whose section stays open while one is wrong. A description's "both languages or neither" is
    // left to the server.
    const name = { required: true, length: { max: 100 } };
    const description = { length: { max: 5000, of: marksLength } };
    const checks = useChecks([
        { id: 'brand-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name },
        { id: 'brand-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name },
        { id: 'brand-position', label: t('catalog::admin.field.position'), value: form.data.position, rules: { required: true, number: { min: 0, max: 10000 } } },
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
                    <TextField
                        id="brand-position"
                        dir="ltr"
                        inputMode="numeric"
                        inputClassName="tw-figure"
                        label={t('catalog::admin.field.position')}
                        helper={t('catalog::admin.field.position_helper')}
                        value={form.data.position}
                        check={checks.box('brand-position', form.errors.position)}
                        onChange={(event) => form.setData('position', toLatinDigits(event.target.value))}
                        data-test="brand-position"
                    />
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

function DeleteBrandDialog({ brand, open, onOpenChange, returnFocusTo }: { brand: BrandData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_brands.delete_title')}
            description={t('catalog::admin_brands.delete_body', { name: nameIn(locale, brand.nameAr, brand.nameEn) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        router.post(`/admin/brands/${brand.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })
                    }
                    data-test="confirm-delete-brand"
                >
                    {t('catalog::admin_brands.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
