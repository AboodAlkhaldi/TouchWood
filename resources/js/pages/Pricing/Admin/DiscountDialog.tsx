import { useState, type RefObject } from 'react';
import { router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { DateTimeField } from '@/components/DateTimeField';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchCombobox } from '@/components/SearchCombobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { toLatinDigits } from '@/lib/digits';
import { isoOf, momentBox, momentFrom, noMoment, type MomentInput } from '@/lib/moments';
import { useChecks } from '@/lib/use-checks';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { CategoryChoiceData, DiscountPreviewData, DiscountRowData, PickedProductData, ProductResultsData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { percentProblem, priceProblem } from './boxes';
import { DiscountPreview } from './DiscountPreview';
import { nameIn, readJson, useLiveRead, useLocale, useT } from './parts';
import { RemoteCombobox } from './picker';

/*
| The category discount dialog (pricing.md §4.3.3 P3): **Add**, **Change** a scheduled one in full, **Change
| End** of a running one. The category - Catalog's categories switched on -, Percent Off or Amount Off (no
| default), the lines it lowers (no default: a choice with consequences, Geist), the dates, Always Wins, and
| the products it leaves out - the category's own and its children's (Q10); a new category clears them,
| said once. Under the fields its preview as typed (`PreviewCategoryDiscount`, 300 ms after a change); a
| depth past the ceiling is said under its box from the preview and refused on Save.
*/

export type DiscountStore = { code: string; decimals: number; currency: string; zone: string; ceilingPercent: number; wired: boolean };
export type DiscountMode = 'add' | 'change' | 'change_end';

type Kind = 'percent' | 'amount';
const LINES = ['RETAIL', 'WHOLESALE', 'BOTH'] as const;

function typedMinor(minor: number, decimals: number): string {
    if (decimals <= 0) {
        return String(minor);
    }

    const digits = String(minor).padStart(decimals + 1, '0');

    return `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}

type Props = {
    mode: DiscountMode;
    store: DiscountStore;
    discount?: DiscountRowData | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
};

export function DiscountDialog({ mode, store, discount = null, open, onOpenChange, returnFocusTo }: Props) {
    const t = useT();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;
    const fixed = mode === 'change_end';
    const [category, setCategory] = useState(discount?.categoryId ?? '');
    const [kind, setKind] = useState<Kind | null>(discount === null ? null : discount.percentBasisPoints !== null ? 'percent' : 'amount');
    const [amount, setAmount] = useState(discount === null ? '' : discount.percentBasisPoints !== null ? String(discount.percentBasisPoints / 100) : discount.amountOff === null ? '' : typedMinor(discount.amountOff.minor, store.decimals));
    const [lines, setLines] = useState(discount?.lines ?? '');
    const [starts, setStarts] = useState<MomentInput>(discount === null ? noMoment() : momentFrom(discount.startsAt, store.zone));
    const [ends, setEnds] = useState<MomentInput>(discount === null ? noMoment() : momentFrom(discount.endsAt, store.zone));
    const [alwaysWins, setAlwaysWins] = useState(discount?.alwaysWins ?? false);
    const [leftOut, setLeftOut] = useState<{ id: string; name: string }[]>(discount === null ? [] : discount.leftOut.map((id, index) => ({ id, name: discount.leftOutNames[index] ?? id })));
    const [cleared, setCleared] = useState(false);
    const [tried, setTried] = useState(false);
    const [busy, setBusy] = useState(false);
    const categories = useLiveRead<CategoryChoiceData[]>('/admin/category-discounts/categories', open && !fixed ? { store: store.code } : null, 0);
    const amountProblem = kind === null || amount.trim() === '' ? null : kind === 'percent' ? percentProblem(amount, t) : priceProblem(amount, store.decimals, t, locale, 'amount');
    const ready = category !== '' && kind !== null && amount.trim() !== '' && amountProblem === null && lines !== '';
    const params = ready
        ? {
              store: store.code,
              category,
              percent: kind === 'percent' ? amount.trim() : null,
              amount: kind === 'amount' ? amount.trim() : null,
              lines,
              left_out: leftOut.map((each) => each.id),
              starts_at: fixed ? null : isoOf(starts, store.zone),
              ends_at: isoOf(ends, store.zone),
              discount: discount?.id ?? null,
          }
        : null;
    const preview = useLiveRead<DiscountPreviewData>('/admin/category-discounts/preview', params);
    const checks = useChecks(
        fixed
            ? [momentBox(ends, { id: 'discount-ends', label: t('pricing::admin.offer.ends'), kind: 'end', zone: store.zone, after: null })]
            : [
                  momentBox(starts, { id: 'discount-starts', label: t('pricing::admin.offer.starts'), kind: 'start', zone: store.zone }),
                  momentBox(ends, { id: 'discount-ends', label: t('pricing::admin.offer.ends'), kind: 'end', zone: store.zone, after: starts }),
              ],
        open,
    );
    const problems = fixed
        ? {}
        : {
              category: category === '' ? t('pricing::admin_discounts.check.category') : undefined,
              kind: kind === null ? t('pricing::admin_discounts.check.kind') : undefined,
              amount: kind !== null && amount.trim() === '' ? (kind === 'percent' ? t('pricing::admin.check.percent_range') : t('pricing::admin_discounts.check.amount_positive')) : (amountProblem ?? undefined),
              lines: lines === '' ? t('pricing::admin_discounts.check.lines') : undefined,
          };
    const shown = (key: 'category' | 'kind' | 'amount' | 'lines') => (tried || (key === 'amount' && amount.trim() !== '') ? (problems as Record<string, string | undefined>)[key] : undefined) ?? errors[key];
    // A depth past the ceiling, from the preview (a store not wired: a wired one skips those sizes, Q9).
    const deep = !store.wired && preview.data !== null && preview.data.deepestBasisPoints !== null && preview.data.deepestBasisPoints > store.ceilingPercent * 100;
    const titles: Record<DiscountMode, string> = { add: t('pricing::admin_discounts.add'), change: t('pricing::admin_discounts.change'), change_end: t('pricing::admin_discounts.change_end') };
    const bodies: Record<DiscountMode, string> = { add: t('pricing::admin_discounts.add_body'), change: t('pricing::admin_discounts.change_body'), change_end: t('pricing::admin_discounts.change_end_body') };

    function send() {
        const done = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) };
        const fields = {
            category,
            percent: kind === 'percent' ? amount.trim() : null,
            amount: kind === 'amount' ? amount.trim() : null,
            lines,
            starts_at: isoOf(starts, store.zone),
            ends_at: isoOf(ends, store.zone),
            always_wins: alwaysWins,
            left_out: leftOut.map((each) => each.id),
        };

        if (mode === 'add') {
            router.post('/admin/category-discounts', { store: store.code, ...fields }, done);
        } else if (mode === 'change' && discount !== null) {
            router.post(`/admin/category-discounts/${discount.id}`, fields, done);
        } else if (discount !== null) {
            router.post(`/admin/category-discounts/${discount.id}/end-date`, { ends_at: isoOf(ends, store.zone) }, done);
        }
    }

    function submit() {
        const wrong = Object.entries(problems).find(([, message]) => message !== undefined);

        if (wrong !== undefined) {
            setTried(true);
            document.getElementById(`discount-${wrong[0]}`)?.focus();

            return;
        }

        checks.submit(send);
    }

    const categoryLabel = (choice: CategoryChoiceData) => choice.path.join(' › ');

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={titles[mode]}
            description={bodies[mode]}
            busy={busy}
            confirm={
                <ActionButton loading={busy} disabledReason={checks.reason} onClick={submit} data-test="confirm-discount">
                    {titles[mode]}
                </ActionButton>
            }
        >
            <div className="grid gap-5">
                {fixed && discount !== null ? (
                    <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-copy-14">
                        <dt className="text-ink-muted">{t('pricing::admin_discounts.column.category')}</dt>
                        <dd>{discount.categoryPath.join(' › ')}</dd>
                        <dt className="text-ink-muted">{t('pricing::admin_discounts.column.lines')}</dt>
                        <dd>{t(`pricing::admin.lines.${discount.lines}`)}</dd>
                    </dl>
                ) : (
                    <SearchCombobox
                        id="discount-category"
                        label={t('pricing::admin_discounts.field.category')}
                        options={(categories.data ?? []).map((choice) => ({ value: choice.id, label: categoryLabel(choice) }))}
                        value={category}
                        onChange={(next) => {
                            if (next !== category && leftOut.length > 0) {
                                setLeftOut([]);
                                setCleared(true);
                            }

                            setCategory(next);
                        }}
                        words={{ search: t('pricing::admin_discounts.field.category_search'), none: (query) => t('pricing::admin_discounts.field.category_none', { query }) }}
                        error={shown('category')}
                        data-test="discount-category"
                    />
                )}

                {fixed ? null : (
                    <FieldSet className="gap-3">
                        <FieldLegend variant="label">{t('pricing::admin_discounts.field.kind')}</FieldLegend>
                        <ToggleGroup
                            id="discount-kind"
                            type="single"
                            value={kind ?? ''}
                            onValueChange={(next) => (next === 'percent' || next === 'amount' ? setKind(next) : undefined)}
                            aria-label={t('pricing::admin_discounts.field.kind')}
                            className={`${SWITCH_TRACK} w-fit`}
                            data-test="discount-kind"
                        >
                            <ToggleGroupItem value="percent" className={SWITCH_ITEM} data-test="discount-kind-percent">
                                {t('pricing::admin_discounts.field.percent')}
                            </ToggleGroupItem>
                            <ToggleGroupItem value="amount" className={SWITCH_ITEM} data-test="discount-kind-amount">
                                {t('pricing::admin_discounts.field.amount')}
                            </ToggleGroupItem>
                        </ToggleGroup>
                        {kind === null && shown('kind') ? <FieldError>{shown('kind')}</FieldError> : null}
                        {kind !== null ? (
                            <Field>
                                <FieldLabel htmlFor="discount-amount">{kind === 'percent' ? t('pricing::admin_discounts.field.percent') : t('pricing::admin_discounts.field.amount')}</FieldLabel>
                                <InputGroup className="w-40">
                                    <InputGroupInput
                                        id="discount-amount"
                                        dir="ltr"
                                        inputMode="decimal"
                                        autoComplete="off"
                                        className="tw-figure"
                                        value={amount}
                                        aria-invalid={shown('amount') || errors.percent ? true : undefined}
                                        aria-describedby={shown('amount') || errors.percent || deep ? 'discount-amount-error' : undefined}
                                        onChange={(event) => setAmount(toLatinDigits(event.target.value))}
                                        data-test="discount-amount"
                                    />
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupText>{kind === 'percent' ? '%' : store.currency}</InputGroupText>
                                    </InputGroupAddon>
                                </InputGroup>
                                {(shown('amount') ?? errors.percent) ? (
                                    <FieldError id="discount-amount-error">{shown('amount') ?? errors.percent}</FieldError>
                                ) : deep ? (
                                    <p id="discount-amount-error" className="text-copy-13 text-warn" data-test="discount-over-ceiling">
                                        {t('pricing::admin_discounts.check.over_ceiling', { ceiling: store.ceilingPercent })}
                                    </p>
                                ) : null}
                            </Field>
                        ) : null}
                    </FieldSet>
                )}

                {fixed ? null : (
                    <FieldSet className="gap-3">
                        <FieldLegend variant="label">{t('pricing::admin_discounts.field.lines')}</FieldLegend>
                        <FieldDescription>{t('pricing::admin_discounts.field.lines_helper')}</FieldDescription>
                        <RadioGroup id="discount-lines" value={lines} onValueChange={setLines} className="flex flex-wrap gap-4" data-test="discount-lines">
                            {LINES.map((each) => (
                                <Field key={each} orientation="horizontal" className="w-auto">
                                    <RadioGroupItem value={each} id={`discount-lines-${each}`} data-test={`discount-lines-${each}`} />
                                    <FieldLabel htmlFor={`discount-lines-${each}`} className="font-normal">
                                        {t(`pricing::admin.lines.${each}`)}
                                    </FieldLabel>
                                </Field>
                            ))}
                        </RadioGroup>
                        {shown('lines') ? <FieldError>{shown('lines')}</FieldError> : null}
                    </FieldSet>
                )}

                {fixed ? null : <DateTimeField id="discount-starts" label={t('pricing::admin.offer.starts')} kind="start" value={starts} onChange={setStarts} zone={store.zone} check={checks.box('discount-starts-time', errors.starts_at)} />}
                <DateTimeField id="discount-ends" label={t('pricing::admin.offer.ends')} kind="end" value={ends} onChange={setEnds} zone={store.zone} after={fixed ? null : starts} check={checks.box('discount-ends-time', errors.ends_at)} />

                {fixed ? null : (
                    <Field orientation="horizontal">
                        <Switch id="discount-always-wins" checked={alwaysWins} onCheckedChange={setAlwaysWins} data-test="discount-always-wins" />
                        <FieldContent>
                            <Label htmlFor="discount-always-wins">{t('pricing::admin.offer.always_wins')}</Label>
                            <FieldDescription>{t('pricing::admin.offer.always_wins_helper')}</FieldDescription>
                        </FieldContent>
                    </Field>
                )}

                {fixed ? null : (
                    <div className="grid gap-2">
                        <RemoteCombobox<PickedProductData>
                            id="discount-left-out"
                            label={t('pricing::admin_discounts.field.left_out')}
                            path="/admin/category-discounts/left-out"
                            params={{ store: store.code, category }}
                            rows={(answer) => (answer as ProductResultsData).rows.filter((row) => !leftOut.some((each) => each.id === row.productId))}
                            keyOf={(item) => item.productId}
                            labelOf={(item) => nameIn(locale, item.nameAr, item.nameEn)}
                            shown={category === '' ? t('pricing::admin_discounts.field.choose_category') : t('pricing::admin_discounts.field.left_out_search')}
                            disabled={category === ''}
                            onChoose={(item) => {
                                setLeftOut((before) => [...before, { id: item.productId, name: nameIn(locale, item.nameAr, item.nameEn) }]);
                                setCleared(false);
                            }}
                            helper={t('pricing::admin_discounts.field.left_out_helper')}
                            error={errors.left_out}
                            words={{ search: t('pricing::admin_discounts.field.left_out_search'), none: (query) => t('pricing::admin_discounts.field.left_out_none', { query }) }}
                        />
                        {cleared ? (
                            <Note variant="secondary" size="small" alert>
                                {t('pricing::admin_discounts.field.left_out_cleared')}
                            </Note>
                        ) : null}
                        {leftOut.length > 0 ? (
                            <ul className="flex flex-wrap gap-2" data-test="discount-left-out-chips">
                                {leftOut.map((each) => (
                                    <li key={each.id}>
                                        <Badge variant="outline" className="gap-1 pe-1">
                                            {each.name}
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-xs"
                                                aria-label={t('pricing::admin_discounts.field.left_out_remove', { name: each.name })}
                                                onClick={() => setLeftOut((before) => before.filter((other) => other.id !== each.id))}
                                            >
                                                <X aria-hidden="true" />
                                            </Button>
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                )}

                {preview.data !== null ? (
                    <DiscountPreview
                        preview={preview.data}
                        loading={preview.loading}
                        zone={store.zone}
                        more={(after) => (params === null ? Promise.resolve(null) : readJson<DiscountPreviewData>('/admin/category-discounts/preview', { ...params, after }))}
                    />
                ) : !fixed ? (
                    <p className="text-copy-13 text-ink-muted">{t('pricing::admin_discounts.preview.choose')}</p>
                ) : null}
            </div>
        </PanelDialog>
    );
}
