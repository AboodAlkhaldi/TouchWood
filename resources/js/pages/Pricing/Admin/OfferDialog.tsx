import { useEffect, useState, type RefObject } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DateTimeField } from '@/components/DateTimeField';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchField } from '@/components/SearchField';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { toLatinDigits } from '@/lib/digits';
import { isoOf, momentBox, momentFrom, noMoment, type MomentInput } from '@/lib/moments';
import { useChecks } from '@/lib/use-checks';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { OverlapNoteData, PickedProductData, ProductResultsData, SaleRowData, SaleSizeChoiceData, SaleViewData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { percentProblem, priceProblem } from './boxes';
import { LoadFailed, Mark, nameIn, Off, OverlapLine, percentOf, RowPhoto, StateBadge, ToArrow, useDate, useDebounced, useLiveRead, useLocale, useT } from './parts';

/*
| The offer dialog (pricing.md §4.3.3 P2; "Offer" is the panel's word for a sale, §9.4) - on Offers, on a
| Prices row ("Add Offer…", the product and that size chosen) and on a product's Stores tab (the product
| chosen). **Add**: the product - the store's ready products switched on (Q13) -, All Sizes (every size
| switched on that has a price, Q39) or Some Sizes, Offer Price or Percent Off (no default), the start (Now)
| and the end (No End), Always Wins. **Change** a scheduled one: its price, dates and Always Wins; its product
| and sizes are kept. **Change End** a running one: the end alone. **View** an ended one: every field.
|
| Under the fields, the note (`OverlapsFor`, refreshed 300 ms after a change): each size's new price and
| whether it wins - today, or at its start - or why not; how deep it goes against the store's ceiling (a
| warning: Save refuses it); what it overlaps, named and dated. The server decides everything on Save.
*/

export type OfferStore = { code: string; decimals: number; currency: string; zone: string };

export type OfferMode = 'add' | 'change' | 'change_end' | 'view';

type Props = {
    mode: OfferMode;
    store: OfferStore;
    /** The offer changed or viewed. */
    sale?: SaleRowData | null;
    /** Add from a row or a product's page: the product already chosen. */
    product?: PickedProductData | null;
    /** Add from a Prices row: that size chosen (Some Sizes). */
    sizes?: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
};

type Kind = 'price' | 'percent';

/** A percentage in basis points as typed: 1250 → "12.5". */
function percentTyped(basisPoints: number): string {
    return String(basisPoints / 100);
}

/** A price as typed in the store's decimals, from what the server wrote. */
function priceTyped(minor: number, decimals: number): string {
    if (decimals <= 0) {
        return String(minor);
    }

    const digits = String(minor).padStart(decimals + 1, '0');

    return `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}

export function OfferDialog({ mode, store, sale = null, product: preset = null, sizes: presetSizes = [], open, onOpenChange, returnFocusTo }: Props) {
    const t = useT();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;
    const adding = mode === 'add';
    const readOnly = mode === 'view';
    const [product, setProduct] = useState<PickedProductData | null>(preset);
    const [all, setAll] = useState(presetSizes.length === 0);
    const [chosen, setChosen] = useState<string[]>(presetSizes);
    const [kind, setKind] = useState<Kind | null>(sale === null ? null : sale.percentBasisPoints !== null ? 'percent' : 'price');
    const [amount, setAmount] = useState(sale === null ? '' : sale.percentBasisPoints !== null ? percentTyped(sale.percentBasisPoints) : sale.salePrice === null ? '' : priceTyped(sale.salePrice.minor, store.decimals));
    const [starts, setStarts] = useState<MomentInput>(sale === null ? noMoment() : momentFrom(sale.startsAt, store.zone));
    const [ends, setEnds] = useState<MomentInput>(sale === null ? noMoment() : momentFrom(sale.endsAt, store.zone));
    const [alwaysWins, setAlwaysWins] = useState(sale?.alwaysWins ?? false);
    const [tried, setTried] = useState(false);
    const [busy, setBusy] = useState(false);
    const sizeChoices = useLiveRead<SaleSizeChoiceData[]>('/admin/offers/sizes', adding && product !== null ? { store: store.code, product: product.productId } : null, 0);
    const viewed = useLiveRead<SaleViewData>(`/admin/offers/${sale?.id ?? ''}`, mode === 'view' && sale !== null ? { open: true } : null, 0);
    const choices = sizeChoices.data ?? [];
    const priced = choices.filter((size) => size.retail !== null).map((size) => size.variantId);

    useEffect(() => {
        // A size chosen from a row that has no price here is not chosen.
        if (sizeChoices.data !== null) {
            setChosen((before) => before.filter((id) => priced.includes(id)));
        }
        // Only the product's sizes arriving changes it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sizeChoices.data]);

    const amountProblem = kind === null || amount.trim() === '' ? null : kind === 'percent' ? percentProblem(amount, t) : priceProblem(amount, store.decimals, t, locale);
    const checks = useChecks(
        mode === 'change_end' || readOnly
            ? [momentBox(ends, { id: 'offer-ends', label: t('pricing::admin.offer.ends'), kind: 'end', zone: store.zone, after: null })]
            : [
                  momentBox(starts, { id: 'offer-starts', label: t('pricing::admin.offer.starts'), kind: 'start', zone: store.zone }),
                  momentBox(ends, { id: 'offer-ends', label: t('pricing::admin.offer.ends'), kind: 'end', zone: store.zone, after: starts }),
              ],
        open,
    );
    const problems = {
        product: adding && product === null ? t('pricing::admin.check.product') : undefined,
        sizes: adding && product !== null && !all && chosen.length === 0 ? t('pricing::admin.check.sizes') : undefined,
        kind: mode !== 'change_end' && kind === null ? t('pricing::admin.check.kind') : undefined,
        amount: mode === 'change_end' ? undefined : kind !== null && amount.trim() === '' ? (kind === 'percent' ? t('pricing::admin.check.percent_range') : t('pricing::admin.check.price_positive')) : (amountProblem ?? undefined),
    };
    // The note follows what is typed, once there is something to judge.
    const valid = amountProblem === null && amount.trim() !== '';
    const noteParams =
        readOnly || (adding && product === null)
            ? null
            : {
                  store: store.code,
                  product: adding ? product?.productId : null,
                  sizes: adding && !all ? chosen : [],
                  sale: sale?.id ?? null,
                  starts_at: mode === 'change_end' ? null : isoOf(starts, store.zone),
                  ends_at: isoOf(ends, store.zone),
                  percent: kind === 'percent' && valid ? amount.trim() : null,
                  price: kind === 'price' && valid ? amount.trim() : null,
                  always_wins: alwaysWins,
              };
    const note = useLiveRead<OverlapNoteData>('/admin/offers/note', noteParams);

    function send() {
        const done = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) };
        const value = { percent: kind === 'percent' ? amount.trim() : null, price: kind === 'price' ? amount.trim() : null };

        if (mode === 'add') {
            router.post('/admin/offers', { store: store.code, product: product?.productId ?? null, sizes: all ? [] : chosen, ...value, starts_at: isoOf(starts, store.zone), ends_at: isoOf(ends, store.zone), always_wins: alwaysWins }, done);
        } else if (mode === 'change' && sale !== null) {
            router.post(`/admin/offers/${sale.id}`, { ...value, starts_at: isoOf(starts, store.zone), ends_at: isoOf(ends, store.zone), always_wins: alwaysWins }, done);
        } else if (mode === 'change_end' && sale !== null) {
            router.post(`/admin/offers/${sale.id}/end-date`, { ends_at: isoOf(ends, store.zone) }, done);
        }
    }

    function submit() {
        const wrong = Object.entries(problems).find(([, message]) => message !== undefined);

        if (wrong !== undefined) {
            setTried(true);
            document.getElementById(`offer-${wrong[0]}`)?.focus();

            return;
        }

        checks.submit(send);
    }

    const titles: Record<OfferMode, string> = {
        add: t('pricing::admin.offer.add'),
        change: t('pricing::admin.offer.change'),
        change_end: t('pricing::admin.offer.change_end'),
        view: t('pricing::admin.offer.view'),
    };
    const bodies: Record<OfferMode, string> = {
        add: t('pricing::admin.offer.add_body'),
        change: t('pricing::admin.offer.change_body'),
        change_end: t('pricing::admin.offer.change_end_body'),
        view: t('pricing::admin.offer.view_body'),
    };
    const shown = (key: keyof typeof problems) => (tried || key === 'amount' ? problems[key] : undefined) ?? errors[key];
    const productName = product !== null ? nameIn(locale, product.nameAr, product.nameEn) : sale !== null ? nameIn(locale, sale.productNameAr, sale.productNameEn) : '';

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={titles[mode]}
            description={bodies[mode]}
            busy={busy}
            cancelLabel={readOnly ? t('ui.close') : undefined}
            confirm={
                readOnly ? null : (
                    <ActionButton loading={busy} disabledReason={checks.reason} onClick={submit} data-test="confirm-offer">
                        {titles[mode]}
                    </ActionButton>
                )
            }
        >
            <div className="grid gap-5">
                {adding && preset === null ? (
                    <ProductPicker store={store.code} product={product} onChoose={setProduct} error={shown('product')} />
                ) : (
                    <div className="grid gap-1">
                        <p className="text-label-14 text-ink">{t('pricing::admin.offer.product')}</p>
                        <p className="text-copy-14 text-ink" data-test="offer-product-name">
                            {productName}
                        </p>
                    </div>
                )}

                {adding && product !== null ? (
                    <FieldSet className="gap-3" data-test="offer-sizes">
                        <FieldLegend variant="label">{t('pricing::admin.offer.sizes')}</FieldLegend>
                        {sizeChoices.failed ? (
                            <LoadFailed what="sizes" onRetry={sizeChoices.retry} />
                        ) : sizeChoices.loading && sizeChoices.data === null ? (
                            <Spinner aria-label={t('ui.loading')} className="text-ink-muted" />
                        ) : choices.length === 0 ? (
                            <p className="text-copy-14 text-ink-muted">{t('pricing::admin.offer.no_sizes')}</p>
                        ) : (
                            <>
                                <RadioGroup value={all ? 'all' : 'some'} onValueChange={(value) => setAll(value === 'all')}>
                                    <Field orientation="horizontal">
                                        <RadioGroupItem value="all" id="offer-all-sizes" data-test="offer-all-sizes" />
                                        <FieldContent>
                                            <FieldLabel htmlFor="offer-all-sizes" className="font-normal">
                                                {t('pricing::admin.offer.all_sizes')}
                                            </FieldLabel>
                                            <FieldDescription>{t('pricing::admin.offer.all_sizes_helper')}</FieldDescription>
                                        </FieldContent>
                                    </Field>
                                    <Field orientation="horizontal">
                                        <RadioGroupItem value="some" id="offer-some-sizes" data-test="offer-some-sizes" />
                                        <FieldLabel htmlFor="offer-some-sizes" className="font-normal">
                                            {t('pricing::admin.offer.some_sizes')}
                                        </FieldLabel>
                                    </Field>
                                </RadioGroup>
                                {all ? null : (
                                    <div id="offer-sizes" tabIndex={-1} className="grid gap-2 ps-6">
                                        {choices.map((size) => (
                                            <Field key={size.variantId} orientation="horizontal" data-disabled={size.retail === null || undefined}>
                                                <Checkbox
                                                    id={`offer-size-${size.variantId}`}
                                                    disabled={size.retail === null}
                                                    checked={chosen.includes(size.variantId)}
                                                    onCheckedChange={(on) => setChosen((before) => (on === true ? [...before, size.variantId] : before.filter((id) => id !== size.variantId)))}
                                                    data-test={`offer-size-${size.variantId}`}
                                                />
                                                <FieldContent>
                                                    <FieldLabel htmlFor={`offer-size-${size.variantId}`} className="font-normal">
                                                        <span>{size.name}</span>
                                                        <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                                            {size.code}
                                                        </span>
                                                        {size.retail !== null ? <Money value={size.retail} className="text-copy-13 text-ink-muted" /> : null}
                                                    </FieldLabel>
                                                    {size.retail === null ? <FieldDescription>{t('pricing::admin.offer.no_price_yet')}</FieldDescription> : null}
                                                </FieldContent>
                                            </Field>
                                        ))}
                                        {shown('sizes') ? <FieldError>{shown('sizes')}</FieldError> : null}
                                    </div>
                                )}
                            </>
                        )}
                    </FieldSet>
                ) : !adding && sale !== null ? (
                    <div className="grid gap-1">
                        <p className="text-label-14 text-ink">{t('pricing::admin.offer.sizes')}</p>
                        <p className="text-copy-14 text-ink">{sale.allSizes ? t('pricing::admin.offer.all_sizes') : sale.sizeNames.join(t('pricing::admin.list_separator'))}</p>
                    </div>
                ) : null}

                {readOnly && sale !== null ? (
                    <>
                        {viewed.failed ? <LoadFailed what="offer" onRetry={viewed.retry} /> : null}
                        <ViewFields sale={sale} view={viewed.data} failed={viewed.failed} zone={store.zone} />
                    </>
                ) : (
                    <>
                        <FieldSet className="gap-3">
                            <FieldLegend variant="label">{t('pricing::admin.offer.kind')}</FieldLegend>
                            <ToggleGroup
                                id="offer-kind"
                                type="single"
                                value={kind ?? ''}
                                disabled={mode === 'change_end'}
                                onValueChange={(next) => (next === 'price' || next === 'percent' ? setKind(next) : undefined)}
                                aria-label={t('pricing::admin.offer.kind')}
                                className={`${SWITCH_TRACK} w-fit`}
                                data-test="offer-kind"
                            >
                                <ToggleGroupItem value="price" className={SWITCH_ITEM} data-test="offer-kind-price">
                                    {t('pricing::admin.offer.price')}
                                </ToggleGroupItem>
                                <ToggleGroupItem value="percent" className={SWITCH_ITEM} data-test="offer-kind-percent">
                                    {t('pricing::admin.offer.percent')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                            {shown('kind') && kind === null ? <FieldError>{shown('kind')}</FieldError> : null}
                            {kind !== null ? (
                                <Field>
                                    <FieldLabel htmlFor="offer-amount">{kind === 'price' ? t('pricing::admin.offer.price') : t('pricing::admin.offer.percent')}</FieldLabel>
                                    <InputGroup className="w-40">
                                        <InputGroupInput
                                            id="offer-amount"
                                            dir="ltr"
                                            inputMode="decimal"
                                            autoComplete="off"
                                            className="tw-figure"
                                            disabled={mode === 'change_end'}
                                            value={amount}
                                            aria-invalid={shown('amount') || errors.percent || errors.price ? true : undefined}
                                            aria-describedby={shown('amount') || errors.percent || errors.price ? 'offer-amount-error' : undefined}
                                            onChange={(event) => setAmount(toLatinDigits(event.target.value))}
                                            data-test="offer-amount"
                                        />
                                        <InputGroupAddon align="inline-end">
                                            <InputGroupText>{kind === 'price' ? store.currency : '%'}</InputGroupText>
                                        </InputGroupAddon>
                                    </InputGroup>
                                    {amount.trim() !== '' || tried ? (
                                        (shown('amount') ?? errors.percent ?? errors.price) ? <FieldError id="offer-amount-error">{shown('amount') ?? errors.percent ?? errors.price}</FieldError> : null
                                    ) : null}
                                </Field>
                            ) : null}
                        </FieldSet>

                        {mode === 'change_end' ? null : (
                            <DateTimeField id="offer-starts" label={t('pricing::admin.offer.starts')} kind="start" value={starts} onChange={setStarts} zone={store.zone} check={checks.box('offer-starts-time', errors.starts_at)} />
                        )}
                        <DateTimeField
                            id="offer-ends"
                            label={t('pricing::admin.offer.ends')}
                            kind="end"
                            value={ends}
                            onChange={setEnds}
                            zone={store.zone}
                            after={mode === 'change_end' ? null : starts}
                            check={checks.box('offer-ends-time', errors.ends_at)}
                        />

                        <Field orientation="horizontal">
                            <Switch id="offer-always-wins" checked={alwaysWins} disabled={mode === 'change_end'} onCheckedChange={setAlwaysWins} data-test="offer-always-wins" />
                            <FieldContent>
                                <Label htmlFor="offer-always-wins">{t('pricing::admin.offer.always_wins')}</Label>
                                <FieldDescription>{t('pricing::admin.offer.always_wins_helper')}</FieldDescription>
                            </FieldContent>
                        </Field>

                        {note.failed ? <LoadFailed what="note" onRetry={note.retry} /> : <OfferNote note={note.data} loading={note.loading} zone={store.zone} />}
                    </>
                )}
            </div>
        </PanelDialog>
    );
}

/** The dialog's product search: the store's ready products switched on, each with its photo and codes. */
function ProductPicker({ store, product, onChoose, error }: { store: string; product: PickedProductData | null; onChoose: (product: PickedProductData | null) => void; error?: string }) {
    const t = useT();
    const locale = useLocale();
    const [query, setQuery] = useState('');
    const typed = useDebounced(query, 300);
    const found = useLiveRead<ProductResultsData>('/admin/offers/products', product === null ? { store, q: typed } : null, 0);

    if (product !== null) {
        return (
            <div className="grid gap-2">
                <p className="text-label-14 text-ink">{t('pricing::admin.offer.product')}</p>
                <div className="flex flex-wrap items-center gap-3">
                    <RowPhoto src={product.photo} />
                    <span className="grid">
                        <span className="text-copy-14 text-ink" data-test="offer-product-name">
                            {nameIn(locale, product.nameAr, product.nameEn)}
                        </span>
                        <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                            {product.codes.join(' · ')}
                        </span>
                    </span>
                    <ActionButton type="button" variant="outline" size="sm" onClick={() => onChoose(null)} data-test="offer-product-change">
                        {t('pricing::admin.offer.product_change')}
                    </ActionButton>
                </div>
            </div>
        );
    }

    const rows = found.data?.rows ?? [];

    return (
        <div className="grid gap-2">
            <Label htmlFor="offer-product">{t('pricing::admin.offer.product')}</Label>
            <SearchField id="offer-product" label={t('pricing::admin.offer.product')} placeholder={t('pricing::admin.offer.product_search')} value={query} onValueChange={setQuery} data-test="offer-product-search" />
            {error ? <FieldError>{error}</FieldError> : null}
            {found.failed ? <LoadFailed what="search" onRetry={found.retry} /> : null}
            <ul className="grid max-h-60 gap-1 overflow-y-auto" aria-busy={found.loading || undefined} data-test="offer-products">
                {rows.length === 0 && !found.loading && found.data !== null ? <li className="px-2 py-1 text-copy-14 text-ink-muted">{t('pricing::admin.offer.product_none', { query: typed })}</li> : null}
                {rows.map((row) => (
                    <li key={row.productId}>
                        <button
                            type="button"
                            onClick={() => onChoose(row)}
                            className="flex w-full items-center gap-3 rounded-md px-2 py-1.5 text-start hover:bg-surface-sunken focus-visible:ring-[3px] focus-visible:outline-none"
                            data-test={`offer-product-${row.productId}`}
                        >
                            <RowPhoto src={row.photo} />
                            <span className="grid min-w-0">
                                <span className="truncate text-copy-14 text-ink">{nameIn(locale, row.nameAr, row.nameEn)}</span>
                                <span className="tw-figure truncate font-mono text-copy-12 text-ink-muted" dir="ltr">
                                    {row.codes.join(' · ')}
                                </span>
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** The note as typed (Q11): each size's new price and whether it wins, the ceiling, what it overlaps. */
function OfferNote({ note, loading, zone }: { note: OverlapNoteData | null; loading: boolean; zone: string }) {
    const t = useT();
    const locale = useLocale();

    if (note === null) {
        return null;
    }

    const deep = note.sizes.filter((size) => size.overCeiling && size.depthBasisPoints !== null);
    const overlaps = [...note.sales, ...note.discounts];

    return (
        <section aria-labelledby="offer-note-title" aria-busy={loading || undefined} className="grid gap-3 rounded-[var(--tw-radius)] bg-surface-sunken p-4" data-test="offer-note">
            <h3 id="offer-note-title" className="flex items-center gap-2 text-heading-14 text-ink">
                {t('pricing::admin.note.title')}
                {loading ? <Spinner aria-label={t('ui.loading')} className="text-ink-muted" /> : null}
            </h3>
            <div className={loading ? 'grid gap-3 opacity-60' : 'grid gap-3'}>
                {note.ceilingPercent !== null ? <p className="text-copy-13 text-ink-muted">{t('pricing::admin.ceiling', { percent: note.ceilingPercent })}</p> : null}
                {note.sizes.length > 0 ? (
                    <Table>
                        <TableCaption className="sr-only">{t('pricing::admin.note.title')}</TableCaption>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('pricing::admin.column.size')}</TableHead>
                                <TableHead>{t('pricing::admin.column.price')}</TableHead>
                                <TableHead>
                                    <span className="sr-only">{t('pricing::admin.column.state')}</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {note.sizes.map((size) => (
                                <TableRow key={size.variantId} data-test={`note-size-${size.variantId}`}>
                                    <TableCell>{size.name}</TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {size.retail !== null ? <Money value={size.retail} className="text-ink-muted" /> : '—'}
                                        {size.newPrice !== null ? (
                                            <>
                                                {' '}
                                                <ToArrow /> <Money value={size.newPrice} />
                                            </>
                                        ) : null}
                                    </TableCell>
                                    <TableCell className="text-copy-13">
                                        {size.wins ? (
                                            <span className="text-good">{note.atStart ? t('pricing::admin.note.wins_at_start') : t('pricing::admin.note.wins_today')}</span>
                                        ) : size.notWinning !== null ? (
                                            <span className="text-ink-muted">{t(`pricing::admin.note.why.${size.notWinning}`)}</span>
                                        ) : null}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                ) : null}
                {deep.map((size) => (
                    <p key={size.variantId} className="text-copy-13 text-warn" data-test="note-over-ceiling">
                        {t('pricing::admin.note.over_ceiling', { size: size.name, percent: percentOf(locale, size.depthBasisPoints ?? 0), ceiling: note.ceilingPercent ?? 0 })}
                    </p>
                ))}
                {overlaps.length > 0 ? (
                    <div className="grid gap-1" data-test="note-overlaps">
                        <p className="text-label-13 text-ink">{t('pricing::admin.note.overlaps')}</p>
                        <ul className="grid gap-1 text-copy-13 text-ink-muted">
                            {overlaps.map((other) => (
                                <li key={other.id}>
                                    <OverlapLine description={other.description} startsAt={other.startsAt} endsAt={other.endsAt} alwaysWins={other.alwaysWins} zone={zone} />
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>
        </section>
    );
}


/** View Offer: every field of an ended offer, and its sizes with their prices (`ViewSale`). */
function ViewFields({ sale, view, failed, zone }: { sale: SaleRowData; view: SaleViewData | null; failed: boolean; zone: string }) {
    const t = useT();
    const date = useDate(zone);

    return (
        <div className="grid gap-4" data-test="offer-view">
            <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-copy-14">
                <dt className="text-ink-muted">{t('pricing::admin.column.price')}</dt>
                <dd>
                    <Off percentBasisPoints={sale.percentBasisPoints} money={sale.salePrice} />
                </dd>
                <dt className="text-ink-muted">{t('pricing::admin.column.starts')}</dt>
                <dd className="tw-figure">{date(sale.startsAt)}</dd>
                <dt className="text-ink-muted">{t('pricing::admin.column.ends')}</dt>
                <dd className="tw-figure">{sale.endsAt === null ? t('pricing::admin.no_end') : date(sale.endsAt)}</dd>
                <dt className="text-ink-muted">{t('pricing::admin.column.state')}</dt>
                <dd>
                    <StateBadge state={sale.state} />
                </dd>
                <dt className="text-ink-muted">{t('pricing::admin.column.always_wins')}</dt>
                <dd>{sale.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : '—'}</dd>
            </dl>
            {view === null ? (
                failed ? null : <Spinner aria-label={t('ui.loading')} className="text-ink-muted" />
            ) : (
                <Table>
                    <TableCaption className="sr-only">{t('pricing::admin.offer.sizes')}</TableCaption>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('pricing::admin.column.size')}</TableHead>
                            <TableHead>{t('pricing::admin.column.code')}</TableHead>
                            <TableHead>{t('pricing::admin.column.retail_price')}</TableHead>
                            <TableHead>{t('pricing::admin.column.price')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {view.sizes.map((size) => (
                            <TableRow key={size.variantId}>
                                <TableCell>
                                    <span className="flex flex-wrap items-center gap-2">
                                        {size.name}
                                        {size.archived ? <Mark tone="amber-subtle">{t('pricing::admin.badge.archived')}</Mark> : null}
                                    </span>
                                </TableCell>
                                <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                                    {size.code}
                                </TableCell>
                                <TableCell>{size.retail !== null ? <Money value={size.retail} /> : '—'}</TableCell>
                                <TableCell>{size.salePrice !== null ? <Money value={size.salePrice} /> : '—'}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </div>
    );
}
