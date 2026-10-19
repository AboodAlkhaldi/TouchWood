import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { ChangedMark, SaveBar, useLeaveGuard } from '@/components/SaveBar';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { figure, toLatinDigits } from '@/lib/digits';
import { firstMarked, rowErrors } from '@/lib/row-errors';
import { keepsBoxes } from '@/lib/visits';
import { countProblem, leavesShort, wholeOf } from './checks';
import { plural, sentence, useLocale, useT } from './parts';

/*
| Counting in the rows (inventory.md §4.3 I1, S5; pricing.md §4.3.1 "Rows that hold boxes"): a box holds what
| is in stock; typed with another number it is **changed** - a dot and "Changed"; Escape puts it back, Enter
| saves. While any is changed the save bar says how many, with Cancel and **Save Counts**, and leaving asks
| first. **Only the rows typed with a new number are sent** (Q19), one stocktake for all, all or none: a
| refusal marks the rows it names, from that save's own answer (a product page draws a set a store card).
| A count leaving orders short says so under its box before saving (Q20) - never a refusal.
*/

export type CountRow = {
    /** The size's id. */
    id: string;
    inStock: number;
    held: number;
    holdingOrders: number;
};

/**
 * @param store  the store's code, which the save names
 * @param prefix  the boxes' ids: `${prefix}-${size id}`
 */
export function useCounts(rows: CountRow[], { store, prefix = 'count' }: { store: string; prefix?: string }) {
    const t = useT();
    const locale = useLocale();
    const [typed, setTyped] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [marked, setMarked] = useState<Record<string, string>>({});
    const focusFirst = useRef(false);
    const byId = new Map(rows.map((row) => [row.id, row]));
    const changedIds = Object.keys(typed).filter((id) => byId.has(id) && wholeOf(typed[id] ?? '') !== byId.get(id)?.inStock);
    const idOf = (id: string) => `${prefix}-${id}`;
    const problem = (id: string) => (changedIds.includes(id) ? sentence(t, locale, countProblem(typed[id] ?? '')) : undefined);

    useEffect(() => {
        if (!focusFirst.current) {
            return;
        }

        focusFirst.current = false;
        const first = firstMarked(rows, (row) => row.id, marked);

        if (first !== undefined) {
            document.getElementById(idOf(first.id))?.focus();
        }
        // Only a new answer moves the focus.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [marked]);

    const count = changedIds.length;

    function save() {
        if (count === 0 || saving) {
            return;
        }

        const wrong = changedIds.find((id) => problem(id) !== undefined);

        if (wrong !== undefined) {
            document.getElementById(idOf(wrong))?.focus();

            return;
        }

        router.post(
            '/admin/stock/counts',
            { store, counts: Object.fromEntries(changedIds.map((id) => [id, (typed[id] ?? '').trim()])) },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setSaving(true);
                    setMarked({});
                },
                onFinish: () => setSaving(false),
                onSuccess: () => setTyped({}),
                onError: (errors) => {
                    focusFirst.current = true;
                    setMarked(rowErrors(errors));
                },
                onNetworkError: () => {
                    toast.error(t('inventory::admin_stock.box.failed'));

                    return false;
                },
            },
        );
    }

    const leave = useLeaveGuard(count > 0, { description: plural(t, locale, 'inventory::admin_stock.box.lost', count) }, keepsBoxes);

    return {
        count,
        saving,
        idOf,
        valueOf: (row: CountRow) => typed[row.id] ?? String(row.inStock),
        changed: (id: string) => changedIds.includes(id),
        error: (id: string) => problem(id) ?? (changedIds.includes(id) ? marked[id] : undefined),
        /** Said before saving, never refused (Q20): the count typed leaves fewer than orders hold. */
        warning: (row: CountRow) => {
            const after = changedIds.includes(row.id) ? wholeOf(typed[row.id] ?? '') : null;

            return after !== null && leavesShort(after, row.held)
                ? plural(t, locale, 'inventory::admin_stock.box.leaves_short', row.holdingOrders, { pieces: figure(locale, row.held) })
                : undefined;
        },
        type: (id: string, value: string) => setTyped((before) => ({ ...before, [id]: value })),
        reset: (id: string) =>
            setTyped((before) => {
                const next = { ...before };
                delete next[id];

                return next;
            }),
        save,
        bar: () => (
            <>
                <SaveBar
                    id={`${prefix}-save-bar`}
                    count={count}
                    message={plural(t, locale, 'inventory::admin_stock.box.changed', count)}
                    saveLabel={t('inventory::admin_stock.box.save')}
                    saving={saving}
                    onSave={save}
                    onCancel={() => setTyped({})}
                />
                {leave}
            </>
        ),
    };
}

type BoxProps = {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    onEscape: () => void;
    onEnter: () => void;
    changed: boolean;
    error?: string;
    warning?: string;
};

/** A count box (§4.3.1): numeric, left to right, Latin digits as typed; its message, warning and Changed mark under it. */
export function CountBox({ id, label, value, onChange, onEscape, onEnter, changed, error, warning }: BoxProps) {
    const described = [error ? `${id}-error` : null, warning ? `${id}-warning` : null, changed ? `${id}-changed` : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="grid gap-1">
            <Input
                id={id}
                dir="ltr"
                inputMode="numeric"
                autoComplete="off"
                className="tw-figure w-28"
                aria-label={label}
                aria-invalid={error ? true : undefined}
                aria-describedby={described}
                data-changed={changed || undefined}
                value={value}
                onChange={(event) => onChange(toLatinDigits(event.target.value))}
                onKeyDown={(event: KeyboardEvent<HTMLInputElement>) => {
                    if (event.key === 'Escape' && changed) {
                        event.preventDefault();
                        event.stopPropagation();
                        onEscape();
                    }

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        onEnter();
                    }
                }}
                data-test={id}
            />
            {error ? (
                <FieldError id={`${id}-error`} className="max-w-56 text-copy-12">
                    {error}
                </FieldError>
            ) : null}
            {warning ? (
                <p id={`${id}-warning`} className="max-w-56 text-copy-12 text-warn" data-test={`${id}-warning`}>
                    {warning}
                </p>
            ) : null}
            {changed ? <ChangedMark id={`${id}-changed`} /> : null}
        </div>
    );
}
