import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { ChangedMark, SaveBar, useLeaveGuard } from '@/components/SaveBar';
import { FieldError } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { toLatinDigits } from '@/lib/digits';
import { firstMarked, rowErrors } from '@/lib/row-errors';
import { keepsBoxes } from '@/lib/visits';
import { minorOf, priceProblem } from './checks';
import { plural, useLocale, useT } from './parts';

export { exampleIn, minorOf, percentProblem, priceProblem, typedOf } from './checks';

/*
| Rows that hold boxes (pricing.md §4.3.1; P1, P5, the product page): a box holds today's price; typed
| with another it is **changed** - a dot and "Changed"; Escape puts it back, Enter saves every changed box,
| Tab moves box to box. While any is changed the save bar says how many, with Cancel and Save Prices (Q40),
| and leaving the page asks first. A save is one command, all or none: a refusal marks the rows it names,
| each with its message under its box, the focus on the first, and keeps what was typed (Q2). A box checks
| itself as it is typed in the store's decimals; comparisons with an offer or a band only warn (Q8) - the
| server decides.
*/


type BoxProps = {
    id: string;
    /** Read out, not shown: "Price for 60 cm · Oak". */
    label: string;
    currency: string;
    value: string;
    onChange: (value: string) => void;
    onEscape?: () => void;
    onEnter?: () => void;
    changed?: boolean;
    error?: string;
    /** A warning as typed (Q8), never a refusal. */
    warning?: string;
    disabled?: boolean;
    children?: ReactNode;
};

/**
 * A price box (§4.3.1): shadcn's input-group with the currency's letters after it, left to right, Latin
 * digits as typed, 128 px wide; its message, warning and Changed mark under it.
 */
export function PriceBox({ id, label, currency, value, onChange, onEscape, onEnter, changed = false, error, warning, disabled = false, children }: BoxProps) {
    const described = [error ? `${id}-error` : null, warning ? `${id}-warning` : null, changed ? `${id}-changed` : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="grid gap-1">
            <InputGroup className="w-32">
                <InputGroupInput
                    id={id}
                    dir="ltr"
                    inputMode="decimal"
                    autoComplete="off"
                    className="tw-figure"
                    aria-label={label}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={described}
                    data-changed={changed || undefined}
                    disabled={disabled}
                    value={value}
                    onChange={(event) => onChange(toLatinDigits(event.target.value))}
                    onKeyDown={(event: KeyboardEvent<HTMLInputElement>) => {
                        if (event.key === 'Escape' && onEscape !== undefined && changed) {
                            event.preventDefault();
                            event.stopPropagation();
                            onEscape();
                        }

                        if (event.key === 'Enter' && onEnter !== undefined) {
                            event.preventDefault();
                            onEnter();
                        }
                    }}
                    data-test={id}
                />
                <InputGroupAddon align="inline-end">
                    <InputGroupText>{currency}</InputGroupText>
                </InputGroupAddon>
            </InputGroup>
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
            {children}
        </div>
    );
}

export type BoxRow = {
    /** The size's id. */
    id: string;
    /** Today's price, as typed ("149.50"), or "" with none. */
    saved: string;
};

/**
 * The boxes of a list's rows, their save bar and the question before leaving (§4.3.1).
 *
 * Each set of boxes is its own: its elements' ids carry its `prefix` - a product page draws one set a store
 * card, and a Prices row's box and the drawer's are two - and a refusal marks only the rows of the save
 * that was refused, read from that save's own answer, never from another set's (the review of step 3).
 *
 * @param store  the store's code, which the save names
 * @param prefix  the boxes' ids: `${prefix}-${size id}`
 */
export function useBoxes(rows: BoxRow[], { store, decimals, prefix = 'price' }: { store: string; decimals: number; prefix?: string }) {
    const t = useT();
    const locale = useLocale();
    const [typed, setTyped] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    // The rows this set's last refused save named, with their messages.
    const [marked, setMarked] = useState<Record<string, string>>({});
    const focusFirst = useRef(false);
    const saved = new Map(rows.map((row) => [row.id, row.saved]));
    const same = (id: string, value: string) => {
        const before = saved.get(id) ?? '';
        const a = minorOf(value, decimals);
        const b = minorOf(before, decimals);

        return a !== null && b !== null ? a === b : value.trim() === before.trim();
    };
    const changedIds = Object.keys(typed).filter((id) => saved.has(id) && !same(id, typed[id] ?? ''));
    const idOf = (id: string) => `${prefix}-${id}`;
    const problem = (id: string): string | undefined => {
        if (!changedIds.includes(id)) {
            return undefined;
        }

        return priceProblem(typed[id] ?? '', decimals, t, locale) ?? undefined;
    };

    // A refusal arrived: the focus goes to the first row it marks (Q2).
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
            '/admin/prices',
            { store, prices: Object.fromEntries(changedIds.map((id) => [id, (typed[id] ?? '').trim()])) },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setSaving(true);
                    setMarked({});
                },
                onFinish: () => setSaving(false),
                // Saved: the boxes show the saved prices again.
                onSuccess: () => setTyped({}),
                // Refused: nothing saved, every box keeps what was typed, the rows it names marked.
                onError: (errors) => {
                    focusFirst.current = true;
                    setMarked(rowErrors(errors));
                },
                onNetworkError: () => {
                    toast.error(t('pricing::admin.box.failed'));

                    return false;
                },
            },
        );
    }

    const leave = useLeaveGuard(count > 0, { description: plural(t, locale, 'pricing::admin.box.lost', count) }, keepsBoxes);

    return {
        count,
        saving,
        /** A box's element id. */
        idOf,
        /** The box's value: as typed, else today's. */
        valueOf: (row: BoxRow) => typed[row.id] ?? row.saved,
        changed: (id: string) => changedIds.includes(id),
        /** Its own check as typed, else the refusal of its row by this set's last save. */
        error: (id: string) => problem(id) ?? (changedIds.includes(id) ? marked[id] : undefined),
        type: (id: string, value: string) => setTyped((before) => ({ ...before, [id]: value })),
        reset: (id: string) =>
            setTyped((before) => {
                const next = { ...before };
                delete next[id];

                return next;
            }),
        save,
        cancel: () => setTyped({}),
        /** The question before leaving alone, where the boxes have their own save (the drawer's Save Price). */
        leave,
        /** The save bar and the question before leaving, drawn once under the rows. */
        bar: (saveLabel: string) => (
            <>
                <SaveBar id={`${prefix}-save-bar`} count={count} message={plural(t, locale, 'pricing::admin.box.changed', count)} saveLabel={saveLabel} saving={saving} onSave={save} onCancel={() => setTyped({})} />
                {leave}
            </>
        ),
    };
}
