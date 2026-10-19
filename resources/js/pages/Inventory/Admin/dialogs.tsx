import { useState, type RefObject } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextareaField, TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchField } from '@/components/SearchField';
import { Field, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { figure, toLatinDigits } from '@/lib/digits';
import { useDebounced, useLiveRead } from '@/lib/live-read';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { SizeChoicesData, StockFiguresData, StockSizeData } from '@/types/generated/Modules/Inventory/Presentation/Http/Resource';
import { countProblem, leavesShort, noteProblem, quantityProblem, reasonsFor, thresholdProblem, wholeOf } from './checks';
import { LoadFailed, Mark, nameIn, plural, RowPhoto, sentence, sizeName, useLocale, useT } from './parts';

/*
| Inventory's dialogs (inventory.md §4.3 I1), on Stock, Low Stock and a product's Stores tab: **Add or Remove
| Stock** - Add or Remove (no default), the quantity, the reason the direction allows (no default), a note,
| what in stock becomes and whether orders are left short; **Set Threshold** - one box, empty for the store's
| default, for one size or the selected rows; **Add Size** (Q24) - a ready size not listed here, then its
| count, saved as a stocktake. Every box checks itself as typed; the server decides.
*/

type Close = { onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> };

export type AdjustTarget = { variantId: string; name: string; figures: StockFiguresData };

export function AdjustDialog({ store, size, onOpenChange, returnFocusTo }: { store: string; size: AdjustTarget } & Close) {
    const t = useT();
    const locale = useLocale();
    const [direction, setDirection] = useState<'ADD' | 'REMOVE' | null>(null);
    const [quantity, setQuantity] = useState('');
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [tried, setTried] = useState(false);
    const [busy, setBusy] = useState(false);
    const { inStock, held, holdingOrders } = size.figures;
    const amount = wholeOf(quantity);
    const after = direction === null || amount === null ? null : direction === 'ADD' ? inStock + amount : inStock - amount;
    const problems = {
        direction: direction === null ? t('inventory::admin_stock.check.direction') : undefined,
        quantity: sentence(t, locale, quantityProblem(quantity, direction === 'REMOVE' ? inStock : null)),
        reason: reason === '' ? t('inventory::admin_stock.check.reason') : undefined,
        note: sentence(t, locale, noteProblem(reason, note)),
    };
    const shown = (key: keyof typeof problems) => (tried || (key === 'quantity' && quantity !== '') || (key === 'note' && note !== '') ? problems[key] : undefined);
    const title = t('inventory::admin_stock.adjust.title');
    const button = direction === 'REMOVE' ? t('inventory::admin_stock.adjust.remove_button') : t('inventory::admin_stock.adjust.add_button');

    function submit() {
        const wrong = Object.entries(problems).find(([, message]) => message !== undefined);

        if (wrong !== undefined) {
            setTried(true);
            document.getElementById(`adjust-${wrong[0]}`)?.focus();

            return;
        }

        router.post(
            '/admin/stock/adjust',
            { store, size: size.variantId, direction, quantity: quantity.trim(), reason, note: note.trim() === '' ? null : note.trim() },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t('inventory::admin_stock.adjust.body', { size: size.name })}
            busy={busy}
            confirm={
                <ActionButton loading={busy} onClick={submit} data-test="confirm-adjust">
                    {direction === null ? title : button}
                </ActionButton>
            }
        >
            <div className="grid gap-4" data-test="adjust-dialog">
                <FieldSet className="gap-2">
                    <FieldLegend variant="label">{t('inventory::admin_stock.adjust.direction')}</FieldLegend>
                    <ToggleGroup
                        id="adjust-direction"
                        type="single"
                        value={direction ?? ''}
                        onValueChange={(next) => {
                            if (next === 'ADD' || next === 'REMOVE') {
                                setDirection(next);
                                // A reason the other direction offered is not this one's.
                                setReason((before) => (reasonsFor(next).includes(before) ? before : ''));
                            }
                        }}
                        aria-label={t('inventory::admin_stock.adjust.direction')}
                        className={`${SWITCH_TRACK} w-fit`}
                        data-test="adjust-direction"
                    >
                        <ToggleGroupItem value="ADD" className={SWITCH_ITEM} data-test="adjust-add">
                            {t('inventory::admin_stock.adjust.add')}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="REMOVE" className={SWITCH_ITEM} data-test="adjust-remove">
                            {t('inventory::admin_stock.adjust.remove')}
                        </ToggleGroupItem>
                    </ToggleGroup>
                    {shown('direction') ? <FieldError>{shown('direction')}</FieldError> : null}
                </FieldSet>

                <TextField
                    id="adjust-quantity"
                    label={t('inventory::admin_stock.adjust.quantity')}
                    dir="ltr"
                    inputMode="numeric"
                    autoComplete="off"
                    inputClassName="tw-figure w-32"
                    value={quantity}
                    error={shown('quantity')}
                    onChange={(event) => setQuantity(toLatinDigits(event.target.value))}
                    data-test="adjust-quantity"
                />

                {direction === null ? null : (
                    <FieldSet className="gap-2">
                        <FieldLegend variant="label">{t('inventory::admin_stock.adjust.reason')}</FieldLegend>
                        <RadioGroup id="adjust-reason" value={reason} onValueChange={setReason} className="flex flex-wrap gap-4" data-test="adjust-reason">
                            {reasonsFor(direction).map((each) => (
                                <Field key={each} orientation="horizontal" className="w-auto">
                                    <RadioGroupItem value={each} id={`adjust-reason-${each}`} data-test={`adjust-reason-${each}`} />
                                    <FieldLabel htmlFor={`adjust-reason-${each}`} className="font-normal">
                                        {t(`inventory::admin_stock.reason.${each}`)}
                                    </FieldLabel>
                                </Field>
                            ))}
                        </RadioGroup>
                        {shown('reason') ? <FieldError>{shown('reason')}</FieldError> : null}
                    </FieldSet>
                )}

                <TextareaField id="adjust-note" label={t('inventory::admin_stock.adjust.note')} rows={3} value={note} error={shown('note')} onChange={(event) => setNote(event.target.value)} data-test="adjust-note" />

                {after !== null && after >= 0 ? (
                    <p className="tw-figure text-copy-14 text-ink" data-test="adjust-becomes">
                        {t('inventory::admin_stock.adjust.becomes', { count: figure(locale, after) })}
                    </p>
                ) : null}
                {after !== null && after >= 0 && leavesShort(after, held) ? (
                    <p className="text-copy-14 text-bad" data-test="adjust-short">
                        {plural(t, locale, 'inventory::admin_stock.adjust.leaves_short', holdingOrders, { pieces: figure(locale, held) })}
                    </p>
                ) : null}
            </div>
        </PanelDialog>
    );
}

/**
 * Set Threshold: one box, empty for the store's default; for one size or several.
 *
 * @param name  the size's name when one, null for the selection
 */
export function ThresholdDialog({
    store,
    sizes,
    name,
    current,
    defaultThreshold,
    onOpenChange,
    returnFocusTo,
    onSaved,
}: { store: string; sizes: string[]; name: string | null; current: number | null; defaultThreshold: number; onSaved?: () => void } & Close) {
    const t = useT();
    const locale = useLocale();
    const [value, setValue] = useState(current === null ? '' : String(current));
    const [busy, setBusy] = useState(false);
    const problem = sentence(t, locale, thresholdProblem(value));

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('inventory::admin_stock.threshold.title')}
            description={name === null ? plural(t, locale, 'inventory::admin_stock.threshold.body_many', sizes.length) : t('inventory::admin_stock.threshold.body_one', { size: name })}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    disabledReason={problem}
                    onClick={() =>
                        router.post(
                            '/admin/stock/thresholds',
                            { store, sizes, threshold: value.trim() },
                            {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                                onSuccess: () => {
                                    onSaved?.();
                                    onOpenChange(false);
                                },
                            },
                        )
                    }
                    data-test="confirm-threshold"
                >
                    {t('inventory::admin_stock.threshold.save')}
                </ActionButton>
            }
        >
            <TextField
                id="threshold-value"
                label={t('inventory::admin_stock.threshold.field')}
                helper={t('inventory::admin_stock.threshold.helper', { default: figure(locale, defaultThreshold) })}
                dir="ltr"
                inputMode="numeric"
                autoComplete="off"
                inputClassName="tw-figure w-32"
                value={value}
                error={problem}
                onChange={(event) => setValue(toLatinDigits(event.target.value))}
                data-test="threshold-value"
            />
        </PanelDialog>
    );
}

/** Add Size (Q24): a ready size not listed here - a listed one opens its row - then its count. */
export function AddSizeDialog({ store, onOpenChange, returnFocusTo }: { store: string } & Close) {
    const t = useT();
    const locale = useLocale();
    const [query, setQuery] = useState('');
    const typed = useDebounced(query, 300);
    const [chosen, setChosen] = useState<StockSizeData | null>(null);
    const [count, setCount] = useState('');
    const [busy, setBusy] = useState(false);
    const found = useLiveRead<SizeChoicesData>('/admin/stock/add-sizes', chosen === null ? { store, q: typed } : null, 0);
    const problem = count === '' ? undefined : sentence(t, locale, countProblem(count));
    const label = (size: StockSizeData) => `${nameIn(locale, size.productNameAr, size.productNameEn)} · ${sizeName(t, size.values)}`;

    return (
        <PanelDialog
            open
            wide
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('inventory::admin_stock.add_size.button')}
            description={t('inventory::admin_stock.add_size.body')}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    disabled={chosen === null || count.trim() === ''}
                    disabledReason={problem}
                    onClick={() =>
                        chosen === null
                            ? undefined
                            : router.post(
                                  '/admin/stock/counts',
                                  { store, counts: { [chosen.variantId]: count.trim() } },
                                  { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) },
                              )
                    }
                    data-test="confirm-add-size"
                >
                    {t('inventory::admin_stock.add_size.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {chosen === null ? (
                    <div className="grid gap-2">
                        <SearchField id="add-size-search" label={t('inventory::admin_stock.add_size.size')} placeholder={t('inventory::admin_stock.add_size.search')} value={query} onValueChange={setQuery} data-test="add-size-search" />
                        {found.failed ? <LoadFailed what="sizes" onRetry={found.retry} /> : null}
                        {found.loading && found.data === null ? <Spinner aria-label={t('ui.loading')} className="text-ink-muted" /> : null}
                        <ul className="grid max-h-72 gap-1 overflow-y-auto" data-test="add-size-results">
                            {found.data !== null && found.data.rows.length === 0 && found.data.alreadyListed.length === 0 ? (
                                <li className="px-2 py-1 text-copy-14 text-ink-muted">
                                    {typed.trim() === '' ? t('inventory::admin_stock.add_size.none_at_all') : t('inventory::admin_stock.add_size.none', { query: typed.trim() })}
                                </li>
                            ) : null}
                            {(found.data?.rows ?? []).map((size) => (
                                <li key={size.variantId}>
                                    <button
                                        type="button"
                                        onClick={() => setChosen(size)}
                                        className="flex w-full items-center gap-3 rounded-md px-2 py-1.5 text-start hover:bg-surface-sunken focus-visible:ring-[3px] focus-visible:outline-none"
                                        data-test={`add-size-${size.variantId}`}
                                    >
                                        <RowPhoto src={size.photo} />
                                        <span className="grid min-w-0">
                                            <span className="truncate text-copy-14 text-ink">{label(size)}</span>
                                            <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                                {size.code}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                            {(found.data?.alreadyListed ?? []).map((size) => (
                                <li key={size.variantId}>
                                    {/* Already listed: it opens its row's drawer instead (Q24). */}
                                    <a
                                        href={`/admin/stock?store=${store}&size=${size.variantId}`}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            onOpenChange(false);
                                            router.get('/admin/stock', { store, size: size.variantId });
                                        }}
                                        className="flex w-full items-center gap-3 rounded-md px-2 py-1.5 text-start hover:bg-surface-sunken focus-visible:ring-[3px] focus-visible:outline-none"
                                        data-test={`add-size-listed-${size.variantId}`}
                                    >
                                        <RowPhoto src={size.photo} />
                                        <span className="grid min-w-0">
                                            <span className="truncate text-copy-14 text-ink">{label(size)}</span>
                                            <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                                {size.code}
                                            </span>
                                        </span>
                                        <Mark tone="gray-subtle">{t('inventory::admin_stock.add_size.listed')}</Mark>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    <>
                        <div className="flex items-center gap-3" data-test="add-size-chosen">
                            <RowPhoto src={chosen.photo} />
                            <span className="grid">
                                <span className="text-copy-14 text-ink">{label(chosen)}</span>
                                <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                    {chosen.code}
                                </span>
                            </span>
                        </div>
                        <TextField
                            id="add-size-count"
                            label={t('inventory::admin_stock.add_size.count')}
                            dir="ltr"
                            inputMode="numeric"
                            autoComplete="off"
                            inputClassName="tw-figure w-32"
                            value={count}
                            error={problem}
                            onChange={(event) => setCount(toLatinDigits(event.target.value))}
                            data-test="add-size-count"
                        />
                    </>
                )}
            </div>
        </PanelDialog>
    );
}
