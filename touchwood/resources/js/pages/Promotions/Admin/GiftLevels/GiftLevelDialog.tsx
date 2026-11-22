import { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DateTimeField } from '@/components/DateTimeField';
import { TextField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchField } from '@/components/SearchField';
import { Toggle } from '@/components/promotions/fields';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { isolate } from '@/lib/bidi';
import { toLatinDigits } from '@/lib/digits';
import { isoOf, isSaved, momentBox, momentFrom, noMoment, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { GiftLevelData, GiftLevelsPage, SizeChoiceData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| New Level… and Edit… (promotions.md §4.1 M4): the amount; the gift - a size found by product name or
| code in this store, its photo shown; individuals and/or companies; dates, optional. A clash with
| another level (§7) is said inside. The tick box that also limits the size's normal sales to its stock
| (owner's Q11 (c)) comes with Inventory's public switch (stage 5).
*/

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    page: GiftLevelsPage;
    /** The level edited; none for a new one. */
    level?: GiftLevelData;
};

/** The gift chosen, with the stock tick box's two facts (Q11 (c)). */
type Gift = { id: string; productName: string | null; code: string | null; photo: string | null; stockDependent: boolean; wired: boolean };

export function GiftLevelDialog({ open, onOpenChange, page, level }: Props) {
    const t = useTranslator();
    const zone = page.storeTimezone;
    const [starts, setStarts] = useState<MomentInput>(level?.startsAt ? momentFrom(level.startsAt, zone) : noMoment());
    const [ends, setEnds] = useState<MomentInput>(level?.endsAt ? momentFrom(level.endsAt, zone) : noMoment());
    const [gift, setGift] = useState<Gift | null>(level === undefined ? null : { id: level.variantId, productName: level.productName, code: level.variantCode, photo: level.photo, stockDependent: level.stockDependent, wired: level.wired });
    const [text, setText] = useState('');
    const [searching, setSearching] = useState(false);
    const first = useRef(true);
    const form = useForm({
        store: page.storeCode,
        amount: level?.amountText ?? '',
        for_public: level?.forPublic ?? true,
        for_company: level?.forCompany ?? true,
        make_stock_dependent: false,
    });
    const errors: Partial<Record<string, string>> = form.errors;
    const decimals = page.exponent;
    const checks = useChecks(
        [
            { id: 'amount', label: t('promotions::admin_gifts.form.amount', { currency: page.currency }), value: form.data.amount, rules: { required: true, number: { decimals, min: decimals === 0 ? 1 : 1 / 10 ** decimals } } },
            momentBox(starts, { id: 'gift-starts', label: t('promotions::admin_gifts.form.starts'), kind: 'start', zone }),
            momentBox(ends, { id: 'gift-ends', label: t('promotions::admin_gifts.form.ends'), kind: 'end', zone, after: starts }),
        ],
        open,
    );

    // The size search runs on the server once typing stops; only the found sizes come back.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const typed = text.trim();
        const timer = window.setTimeout(() => {
            router.get('/admin/gift-levels', typed.length >= 2 ? { store: page.storeCode, search: typed } : { store: page.storeCode }, {
                only: ['sizes'],
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [text, page.storeCode]);

    // A moment left as it was saved goes back as the server sent it, its seconds kept.
    const sent = (input: MomentInput, saved: string | null | undefined): string | null => (saved && isSaved(input) ? saved : isoOf(input, zone));

    function save() {
        checks.submit(() => {
            form.transform((data) => ({ ...data, make_stock_dependent: stockBox && data.make_stock_dependent, gift_variant: gift?.id ?? '', starts_at: sent(starts, level?.startsAt), ends_at: sent(ends, level?.endsAt) }));
            const done = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

            if (level === undefined) {
                form.post('/admin/gift-levels', done);
            } else {
                form.post(`/admin/gift-levels/${level.id}`, done);
            }
        });
    }

    const pick = (size: SizeChoiceData) => {
        setGift({ id: size.id, productName: size.productName, code: size.code, photo: size.photo, stockDependent: size.stockDependent, wired: size.wired });
        // A new size starts unticked: the box is about the size chosen.
        form.setData('make_stock_dependent', false);
    };
    // Offered only where it would change something: a wired store, a size not yet limited to its stock.
    const stockBox = gift !== null && gift.wired && !gift.stockDependent;

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            wide
            title={level === undefined ? t('promotions::admin_gifts.form.new_title') : t('promotions::admin_gifts.form.edit_title')}
            description={t('promotions::admin_gifts.form.description')}
            busy={form.processing}
            confirm={
                <ActionButton type="button" loading={form.processing} disabledReason={gift === null ? t('promotions::admin_gifts.form.choose_gift') : checks.reason} onClick={save} data-test="save-level">
                    {level === undefined ? t('promotions::admin_gifts.form.create') : t('promotions::admin_gifts.form.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {errors.clash ? (
                    <Note variant="error" alert>
                        {errors.clash}
                    </Note>
                ) : null}

                <TextField
                    id="amount"
                    label={t('promotions::admin_gifts.form.amount', { currency: page.currency })}
                    helper={t('promotions::admin_gifts.form.amount_hint')}
                    inputMode="decimal"
                    dir="ltr"
                    required
                    check={checks.box('amount', errors.amount)}
                    value={form.data.amount}
                    onChange={(event) => form.setData('amount', toLatinDigits(event.target.value))}
                />

                <FieldSet>
                    <FieldLegend className="text-label-14 text-ink">{t('promotions::admin_gifts.form.gift')}</FieldLegend>
                    {gift === null ? null : (
                        <div className="flex items-center gap-3 rounded-[var(--tw-radius)] border border-line p-2" data-test="chosen-gift">
                            <Photo src={gift.photo} />
                            <div className="min-w-0">
                                <p className="truncate text-label-14 text-ink">{gift.productName ?? t('promotions::admin_gifts.list.gone')}</p>
                                {gift.code === null ? null : (
                                    <p className="font-mono text-copy-13 text-ink-muted">
                                        <bdi dir="ltr">{gift.code}</bdi>
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                    <SearchField id="search-size" aria-describedby={errors.gift_variant ? 'gift-error' : undefined} aria-invalid={errors.gift_variant ? true : undefined} label={t('promotions::admin_gifts.form.search')} placeholder={t('promotions::admin_gifts.form.search')} value={text} onValueChange={setText} />
                    {text.trim().length < 2 ? (
                        <p className="text-copy-13 text-ink-muted">{t('promotions::admin_gifts.form.type_two')}</p>
                    ) : searching || page.sizes.length === 0 ? (
                        <p role="status" className="py-4 text-center text-copy-14 text-ink-muted">
                            {searching ? t('promotions::admin_gifts.form.searching') : t('promotions::admin_gifts.form.none')}
                        </p>
                    ) : (
                        <div role="list" className="grid max-h-64 gap-1 overflow-y-auto" data-test="sizes">
                            {page.sizes.map((size) => {
                                const chosen = gift?.id === size.id;

                                return (
                                    <div key={size.id} role="listitem" className="flex items-center justify-between gap-3 rounded-[var(--tw-radius)] px-2 py-1.5 hover:bg-surface-sunken">
                                        <span className="flex min-w-0 items-center gap-3">
                                            <Photo src={size.photo} />
                                            <span className="min-w-0">
                                                <span className="block truncate text-label-14 text-ink">{size.productName}</span>
                                                <bdi dir="ltr" className="block font-mono text-copy-13 text-ink-muted">
                                                    {size.code}
                                                </bdi>
                                            </span>
                                        </span>
                                        <ActionButton
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabledReason={chosen ? t('promotions::admin_gifts.form.is_chosen') : undefined}
                                            aria-label={t('promotions::admin_gifts.form.choose_named', { name: size.productName, code: isolate(size.code) })}
                                            onClick={() => pick(size)}
                                            data-test={`choose-${size.id}`}
                                        >
                                            {chosen ? t('promotions::admin_gifts.form.chosen') : t('promotions::admin_gifts.form.choose')}
                                        </ActionButton>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    {errors.gift_variant ? (
                        <p id="gift-error" role="alert" className="text-copy-13 text-bad">
                            {errors.gift_variant}
                        </p>
                    ) : null}
                </FieldSet>

                {stockBox ? (
                    <Field orientation="horizontal" className="items-start gap-3" data-test="stock-box">
                        <Checkbox
                            id="make_stock_dependent"
                            checked={form.data.make_stock_dependent}
                            // Out of reach without the stock job, yet still focusable, so its reason is heard
                            // (aria-disabled, never `disabled`; as the role editor's boxes).
                            aria-disabled={page.mayManageStock ? undefined : true}
                            onCheckedChange={(on) => (page.mayManageStock ? form.setData('make_stock_dependent', on === true) : undefined)}
                            className="aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            aria-describedby={page.mayManageStock ? 'stock-line' : 'stock-line stock-reason'}
                        />
                        <div className="grid gap-1">
                            <FieldLabel htmlFor="make_stock_dependent" className="text-label-14 font-normal text-ink">
                                {t('promotions::admin_gifts.form.stock_box')}
                            </FieldLabel>
                            <FieldDescription id="stock-line" className="text-copy-13 text-ink-muted">
                                {t('promotions::admin_gifts.form.stock_line')}
                            </FieldDescription>
                            {page.mayManageStock ? null : (
                                <FieldDescription id="stock-reason" className="text-copy-13 text-ink-muted" data-test="stock-reason">
                                    {t('promotions::admin_gifts.form.stock_reason')}
                                </FieldDescription>
                            )}
                        </div>
                    </Field>
                ) : null}

                <FieldSet>
                    <FieldLegend className="text-label-14 text-ink">{t('promotions::admin_gifts.form.who')}</FieldLegend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Toggle id="for_public" label={t('promotions::admin_gifts.who.individuals')} checked={form.data.for_public} onChange={(on) => form.setData('for_public', on)} invalid={errors.buyers ? 'buyers-error' : undefined} />
                        <Toggle id="for_company" label={t('promotions::admin_gifts.who.companies')} checked={form.data.for_company} onChange={(on) => form.setData('for_company', on)} invalid={errors.buyers ? 'buyers-error' : undefined} />
                    </div>
                    {errors.buyers ? (
                        <p id="buyers-error" role="alert" className="text-copy-13 text-bad">
                            {errors.buyers}
                        </p>
                    ) : null}
                </FieldSet>

                <div className="grid gap-4 sm:grid-cols-2">
                    <DateTimeField id="gift-starts" label={t('promotions::admin_gifts.form.starts')} kind="start" value={starts} onChange={setStarts} zone={zone} check={checks.box('gift-starts-time', errors.starts_at ?? errors.dates)} />
                    <DateTimeField id="gift-ends" label={t('promotions::admin_gifts.form.ends')} kind="end" value={ends} onChange={setEnds} zone={zone} after={starts} check={checks.box('gift-ends-time', errors.ends_at)} />
                </div>
            </div>
        </PanelDialog>
    );
}

function Photo({ src }: { src: string | null }) {
    return src === null ? <span className="size-10 shrink-0 rounded-[var(--tw-radius)] bg-surface-sunken" aria-hidden="true" /> : <img src={src} alt="" className="size-10 shrink-0 rounded-[var(--tw-radius)] object-cover" />;
}
