import { useEffect, useRef, useState } from 'react';
import type { RequestPayload } from '@inertiajs/core';
import { router, useForm, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { LabelChoiceData, ProductPage, StoreCardData, VariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { nameIn, useLocale } from '../parts';
import { ProductShell } from './shell';

/*
| A product's Stores (catalog.md §4.4 S9 "Stores", P6): one card per store the reader covers - a Super
| Admin's off stores too, marked Off - not a store filter, as the owner described the page (§1.3).
| Each card: the whole product On in This Store and each variant's On, Retail and Wholesale; Not
| Available Now, the whole product's and each variant's; the Quantities, saved together; the Labels.
| Every switch saves as it flips (Geist's Switch). A control is drawn for whoever holds its job in that
| store; anyone else sees the state, read only. A store that never chose the product offers Switch On
| only. Prices and stock come with their modules.
*/

const LABELS_MAX = 10;

export function StoresTab({ page }: { page: ProductPage }) {
    const t = useTranslator();
    const stores = page.stores ?? [];

    return stores.length === 0 ? (
        <Empty className="material-base" data-test="stores-empty">
            <EmptyHeader>
                <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_products.stores.none')}</EmptyTitle>
            </EmptyHeader>
        </Empty>
    ) : (
        <div className="grid gap-6">
            {stores.map((store) => (
                <StoreCard key={store.storeId} page={page} store={store} />
            ))}
        </div>
    );
}

function StoreCard({ page, store }: { page: ProductPage; store: StoreCardData }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const [labelling, setLabelling] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const { product } = page;
    const ready = product.stage === 'READY';
    // The store by its code, as every store screen's address names it.
    const url = `/admin/products/${product.id}/stores/${store.code}`;
    // The variants a store can sell: archived ones are switched off everywhere (§1.2).
    const variants = (page.variants ?? []).filter((variant) => !variant.archived);
    const rowOf = (variant: VariantData) => store.variants.find((row) => row.variantId === variant.id);
    const onCount = variants.filter((variant) => rowOf(variant)?.on === true).length;
    const readOnly = !store.mayChoose && !store.maySell && !store.mayUnavailable && !store.mayLabel;
    const send = (path: string, data: RequestPayload) => router.post(`${url}/${path}`, data, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    // A way of selling saves with the quantities kept: the store's terms change together.
    const quantities = {
        retail_minimum: store.retailMinimum,
        retail_maximum: store.retailMaximum,
        wholesale_minimum: store.wholesaleMinimum,
        wholesale_maximum: store.wholesaleMaximum,
    };
    const labels = (page.labels ?? []).filter((label) => store.labelIds.includes(label.id));

    return (
        <Card className="material-base border-0" data-test={`store-${store.code}`}>
            <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                <div className="grid gap-1">
                    <CardTitle className="flex flex-wrap items-center gap-2 text-heading-16 text-ink">
                        {store.name}
                        <span className="font-mono text-copy-13 text-ink-muted" dir="ltr">
                            {store.code}
                        </span>
                        {!store.active ? <Badge className={tone('gray-subtle')}>{t('catalog::admin_products.stores.off')}</Badge> : null}
                    </CardTitle>
                    {!store.active ? <CardDescription className="text-copy-13 text-ink-muted">{t('catalog::admin_products.stores.off_hint')}</CardDescription> : null}
                </div>
                {!store.chosen ? (
                    <ActionButton
                        loading={busy}
                        disabledReason={!store.mayChoose ? t('catalog::admin_products.stores.read_only') : !ready ? t('catalog::admin_products.stores.ready_only') : undefined}
                        onClick={() => send('choose', { active: true })}
                        data-test={`switch-on-${store.code}`}
                    >
                        {t('catalog::admin_products.stores.switch_on')}
                    </ActionButton>
                ) : null}
            </CardHeader>

            <CardContent className="grid gap-6">
                {readOnly ? <Note data-test={`store-read-only-${store.code}`}>{t('catalog::admin_products.stores.read_only')}</Note> : null}

                {!store.chosen ? (
                    <p className="text-copy-14 text-ink-muted">{t('catalog::admin_products.stores.never')}</p>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field orientation="horizontal">
                                <FieldContent>
                                    <FieldLabel htmlFor={`on-${store.code}`} className="text-label-14 text-ink">
                                        {t('catalog::admin_products.stores.on')}
                                    </FieldLabel>
                                    <FieldDescription id={`on-${store.code}-count`} className="tw-figure text-copy-13 text-ink-muted">
                                        {t('catalog::admin_products.stores.on_count', { on: figure(locale, onCount), total: figure(locale, variants.length) })}
                                    </FieldDescription>
                                </FieldContent>
                                <Switch
                                    id={`on-${store.code}`}
                                    checked={variants.length > 0 && onCount === variants.length}
                                    disabled={busy || !store.mayChoose || (!ready && onCount < variants.length)}
                                    onCheckedChange={(on) => send('choose', { active: on })}
                                    aria-describedby={`on-${store.code}-count`}
                                    className="data-[state=unchecked]:bg-ink-subtle"
                                    data-test={`store-on-${store.code}`}
                                />
                            </Field>
                            <Field orientation="horizontal">
                                <FieldContent>
                                    <FieldLabel htmlFor={`unavailable-${store.code}`} className="text-label-14 text-ink">
                                        {t('catalog::admin_products.stores.not_available')}
                                    </FieldLabel>
                                    <FieldDescription id={`unavailable-${store.code}-help`} className="text-copy-13 text-ink-muted">
                                        {t('catalog::admin_products.stores.not_available_help')}
                                    </FieldDescription>
                                </FieldContent>
                                <Switch
                                    id={`unavailable-${store.code}`}
                                    checked={store.notAvailable}
                                    disabled={busy || !store.mayUnavailable}
                                    onCheckedChange={(on) => send('unavailable', { unavailable: on })}
                                    aria-describedby={`unavailable-${store.code}-help`}
                                    className="data-[state=unchecked]:bg-ink-subtle"
                                    data-test={`store-unavailable-${store.code}`}
                                />
                            </Field>
                        </div>

                        <div className="overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('catalog::admin_products.stores.variants_caption', { store: store.name })}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('catalog::admin_products.stores.column.variant')}</TableHead>
                                        <TableHead>{t('catalog::admin_products.stores.column.on')}</TableHead>
                                        <TableHead>{t('catalog::admin_products.stores.column.retail')}</TableHead>
                                        <TableHead>{t('catalog::admin_products.stores.column.wholesale')}</TableHead>
                                        <TableHead>{t('catalog::admin_products.stores.column.not_available')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {variants.map((variant) => {
                                        const row = rowOf(variant);
                                        const named = (what: string) => t('catalog::admin_products.stores.switch_named', { what: t(`catalog::admin_products.stores.column.${what}`), variant: variant.code });
                                        // Retail, wholesale and not available now are a variant's row in the store: it has one once chosen there.
                                        const selling = (mode: 'retail' | 'wholesale', on: boolean) =>
                                            send('selling', { ...quantities, modes: { [variant.id]: { retail: mode === 'retail' ? on : (row?.retail ?? false), wholesale: mode === 'wholesale' ? on : (row?.wholesale ?? false) } } });

                                        return (
                                            <TableRow key={variant.id} data-test={`store-${store.code}-variant-${variant.id}`}>
                                                <TableCell>
                                                    <span className="grid">
                                                        <span className="tw-figure font-mono text-copy-13 text-ink" dir="ltr">
                                                            {variant.code}
                                                        </span>
                                                        <span className="text-copy-13 text-ink-muted">{variant.values.map((value) => nameIn(locale, value.nameAr, value.nameEn)).join(' · ')}</span>
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <Switch
                                                        checked={row?.on === true}
                                                        disabled={busy || !store.mayChoose || (!ready && row?.on !== true)}
                                                        onCheckedChange={(on) => send('choose', { active: on, variant_ids: [variant.id] })}
                                                        aria-label={named('on')}
                                                        className="data-[state=unchecked]:bg-ink-subtle"
                                                        data-test="variant-on"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Switch
                                                        checked={row?.retail === true}
                                                        disabled={busy || !store.maySell || row === undefined}
                                                        onCheckedChange={(on) => selling('retail', on)}
                                                        aria-label={named('retail')}
                                                        className="data-[state=unchecked]:bg-ink-subtle"
                                                        data-test="variant-retail"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Switch
                                                        checked={row?.wholesale === true}
                                                        disabled={busy || !store.maySell || row === undefined}
                                                        onCheckedChange={(on) => selling('wholesale', on)}
                                                        aria-label={named('wholesale')}
                                                        className="data-[state=unchecked]:bg-ink-subtle"
                                                        data-test="variant-wholesale"
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Switch
                                                        checked={row?.notAvailable === true}
                                                        disabled={busy || !store.mayUnavailable || row === undefined}
                                                        onCheckedChange={(on) => send('unavailable', { unavailable: on, variant_id: variant.id })}
                                                        aria-label={named('not_available')}
                                                        className="data-[state=unchecked]:bg-ink-subtle"
                                                        data-test="variant-unavailable"
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>

                        <Quantities store={store} url={url} />

                        <div className="grid gap-2">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h3 className="text-label-14 text-ink">{t('catalog::admin_products.stores.labels')}</h3>
                                {store.mayLabel ? (
                                    <ActionButton
                                        type="button"
                                        variant="outline"
                                        loading={busy}
                                        onClick={(event) => {
                                            opener.current = event.currentTarget;
                                            setLabelling(true);
                                        }}
                                        data-test={`labels-${store.code}`}
                                    >
                                        {t('catalog::admin_products.stores.labels_edit')}
                                    </ActionButton>
                                ) : null}
                            </div>
                            {labels.length === 0 ? (
                                <p className="text-copy-14 text-ink-muted">{t('catalog::admin_products.stores.labels_none')}</p>
                            ) : (
                                <ul className="flex flex-wrap gap-2" data-test={`store-labels-${store.code}`}>
                                    {labels.map((label) => (
                                        <li key={label.id}>
                                            <Badge className={tone(label.tone as Tone)}>{nameIn(locale, label.nameAr, label.nameEn)}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </>
                )}
            </CardContent>
            <CardFooter>
                <p className="text-copy-13 text-ink-muted">{t('catalog::admin_products.stores.prices_note')}</p>
            </CardFooter>

            {labelling ? <LabelsDialog store={store} url={url} labels={page.labels ?? []} onOpenChange={(open) => (open ? undefined : setLabelling(false))} returnFocusTo={opener} /> : null}
        </Card>
    );
}

type QuantitiesForm = { retail_minimum: string; retail_maximum: string; wholesale_minimum: string; wholesale_maximum: string };

/** The store's quantities for each way of selling, saved together - out of reach until one changed. */
function Quantities({ store, url }: { store: StoreCardData; url: string }) {
    const t = useTranslator();
    const text = (value: number | null) => (value === null ? '' : String(value));
    const initial = (): QuantitiesForm => ({
        retail_minimum: String(store.retailMinimum),
        retail_maximum: text(store.retailMaximum),
        wholesale_minimum: text(store.wholesaleMinimum),
        wholesale_maximum: text(store.wholesaleMaximum),
    });
    const form = useForm<QuantitiesForm>(initial());

    // The form follows the store's row as the server has it after each save.
    useEffect(() => {
        form.setDefaults(initial());
        form.reset();
    }, [store.retailMinimum, store.retailMaximum, store.wholesaleMinimum, store.wholesaleMaximum]);

    const field = (key: keyof QuantitiesForm) => (
        <TextField
            id={`${key}-${store.code}`}
            dir="ltr"
            inputMode="numeric"
            inputClassName="tw-figure"
            disabled={!store.maySell}
            label={t(`catalog::admin_products.stores.${key}`)}
            value={form.data[key]}
            error={form.errors[key]}
            onChange={(event) => form.setData(key, toLatinDigits(event.target.value))}
            data-test={`${key}-${store.code}`}
        />
    );

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`${url}/selling`, { preserveScroll: true });
            }}
        >
            <FieldSet>
                <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_products.stores.quantities')}</FieldLegend>
                <div className="grid gap-4 sm:grid-cols-4">
                    {field('retail_minimum')}
                    {field('retail_maximum')}
                    {field('wholesale_minimum')}
                    {field('wholesale_maximum')}
                </div>
                <FieldDescription className="text-copy-13 text-ink-muted">{t('catalog::admin_products.stores.quantities_helper')}</FieldDescription>
                {store.maySell ? (
                    <div className="flex justify-end">
                        <ActionButton type="submit" loading={form.processing} disabledReason={!form.isDirty ? t('catalog::admin_products.details.no_changes') : undefined} data-test={`save-quantities-${store.code}`}>
                            {t('catalog::admin_products.stores.save_quantities')}
                        </ActionButton>
                    </div>
                ) : null}
            </FieldSet>
        </form>
    );
}

/** The labels a store puts on the product: the active ones, as their badges, 10 at most. */
function LabelsDialog({
    store,
    url,
    labels,
    onOpenChange,
    returnFocusTo,
}: {
    store: StoreCardData;
    url: string;
    labels: LabelChoiceData[];
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;
    const form = useForm<{ label_ids: string[] }>({ label_ids: store.labelIds.filter((id) => labels.some((label) => label.id === id)) });
    const full = form.data.label_ids.length >= LABELS_MAX;
    const toggle = (id: string, on: boolean) => form.setData('label_ids', on ? [...form.data.label_ids, id] : form.data.label_ids.filter((each) => each !== id));

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_products.stores.labels_title', { store: store.name })}
            description={t('catalog::admin_products.stores.labels_body', { max: LABELS_MAX })}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} onClick={() => form.post(`${url}/labels`, { preserveScroll: true, onSuccess: () => onOpenChange(false) })} data-test="save-labels">
                    {t('catalog::admin_products.stores.save_labels')}
                </ActionButton>
            }
        >
            {labels.length === 0 ? (
                <p className="text-copy-14 text-ink-muted">{t('catalog::admin_products.stores.labels_empty')}</p>
            ) : (
                <FieldSet>
                    <FieldLegend className="sr-only">{t('catalog::admin_products.stores.labels')}</FieldLegend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {labels.map((label) => {
                            const checked = form.data.label_ids.includes(label.id);

                            return (
                                <div key={label.id} className="flex items-center gap-2">
                                    <Checkbox id={`label-${label.id}`} checked={checked} disabled={!checked && full} onCheckedChange={(on) => toggle(label.id, on === true)} data-test={`label-${label.id}`} />
                                    <Label htmlFor={`label-${label.id}`}>
                                        <Badge className={tone(label.tone as Tone)}>{nameIn(locale, label.nameAr, label.nameEn)}</Badge>
                                    </Label>
                                </div>
                            );
                        })}
                    </div>
                    {full ? <FieldDescription className="text-copy-13 text-ink-muted">{t('catalog::admin_products.stores.labels_max', { max: LABELS_MAX })}</FieldDescription> : null}
                    {errors.labels ? <FieldError>{errors.labels}</FieldError> : null}
                </FieldSet>
            )}
        </PanelDialog>
    );
}

/** The Stores tab's page: this product above its tabs, this one open (ProductShell). */
export default function StoresTabPage(page: ProductPage) {
    return (
        <ProductShell page={page}>
            <StoresTab page={page} />
        </ProductShell>
    );
}
