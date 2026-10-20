import { lazy, Suspense, useState } from 'react';
import { X } from 'lucide-react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DateTimeField } from '@/components/DateTimeField';
import { TextField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Choice, Toggle } from '@/components/promotions/fields';
import { isolate } from '@/lib/bidi';
import { figure, toLatinDigits } from '@/lib/digits';
import { isoOf, isSaved, momentBox, momentFrom, noMoment, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { useLocale } from '@/components/promotions/parts';
import type { Picked, Target } from '@/pages/Promotions/Admin/Coupons/RulePicker';
import type { CouponPage, RuleData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M2's Details - a coupon's form (promotions.md §4.1; owner's Q1: a page of its own), in six groups and
| one Save Coupon at the foot, out of reach until something changed. Read only, with no button at all,
| for a reader who may not manage the store's coupons (Q16).
|
| The boxes keep the server's own rules (CouponTerms): names 1 to 100 characters, a percentage 1 to 100,
| amounts in the store's currency with its decimals, uses 1 or more. The code is typed only when the
| coupon is new and never changes (§1.1). A coupon given to customers stays "only customers it is given
| to" while they have it (answer 25). The rule lists name every entry (Q4, Q5).
*/

// The picker loads when it is first opened: it is not part of the page's first paint (frontend.md §5).
const RulePicker = lazy(() => import('./RulePicker').then((module) => ({ default: module.RulePicker })));

/** A code as the server takes one (§1.1): Latin letters, digits and hyphens. */
const CODE = /^[A-Za-z0-9-]+$/;

type Rule = { kind: 'INCLUDE' | 'EXCLUDE'; target: Target; id: string; name: string | null; path: string | null; sold: boolean };

type FormData = {
    store: string;
    code: string;
    name_ar: string;
    name_en: string;
    eligibility: string;
    discount_kind: string;
    percent: string;
    cap: string;
    amount: string;
    minimum: string;
    max_uses_per_customer: string;
    max_uses_total: string;
    allow_with_points: boolean;
    on_reduced_lines: boolean;
    for_retail: boolean;
    for_wholesale: boolean;
    for_public: boolean;
    for_company: boolean;
    rules: Rule[];
};

const NUMBER = (value: number | null): string => (value === null ? '' : String(value));

export function CouponForm({ page }: { page: CouponPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const coupon = page.coupon;
    const zone = page.storeTimezone;
    const readOnly = !page.mayManage;
    const [starts, setStarts] = useState<MomentInput>(coupon === null ? noMoment() : momentFrom(coupon.startsAt, zone));
    const [ends, setEnds] = useState<MomentInput>(coupon === null ? noMoment() : momentFrom(coupon.endsAt, zone));
    const [picking, setPicking] = useState<'INCLUDE' | 'EXCLUDE' | null>(null);
    const form = useForm<FormData>({
        store: page.storeCode,
        code: coupon?.code ?? '',
        name_ar: coupon?.nameAr ?? '',
        name_en: coupon?.nameEn ?? '',
        eligibility: coupon?.eligibility ?? 'PUBLIC',
        discount_kind: coupon?.discountKind ?? 'PERCENT',
        percent: NUMBER(coupon?.percent ?? null),
        cap: coupon?.cap ?? '',
        amount: coupon?.amount ?? '',
        minimum: coupon?.minimum ?? '',
        max_uses_per_customer: NUMBER(coupon?.maxUsesPerCustomer ?? null),
        max_uses_total: NUMBER(coupon?.maxUsesTotal ?? null),
        allow_with_points: coupon?.allowWithPoints ?? true,
        on_reduced_lines: coupon?.onReducedLines ?? false,
        for_retail: coupon?.forRetail ?? true,
        for_wholesale: coupon?.forWholesale ?? true,
        for_public: coupon?.forPublic ?? true,
        for_company: coupon?.forCompany ?? true,
        rules: (coupon?.rules ?? []).map((rule: RuleData) => ({ kind: rule.kind === 'EXCLUDE' ? 'EXCLUDE' : 'INCLUDE', target: rule.target as Target, id: rule.id, name: rule.name, path: rule.path, sold: rule.sold })),
    });
    // The server names some refusals after more than one box (CouponForm::FIELDS): name, dates, max_uses.
    const errors: Partial<Record<string, string>> = form.errors;
    const percent = form.data.discount_kind === 'PERCENT';
    const decimals = page.exponent;
    const money = { decimals, min: decimals === 0 ? 1 : 1 / 10 ** decimals };
    const checks = useChecks([
        ...(coupon === null ? [{ id: 'code', label: t('promotions::admin_coupons.form.code'), value: form.data.code, rules: { required: true, length: { min: 3, max: 30 }, format: { pattern: CODE, key: 'promotions::admin_coupons.check.code' } } }] : []),
        { id: 'name_ar', label: t('promotions::admin_coupons.form.name_ar'), value: form.data.name_ar, rules: { required: true, length: { max: 100 } } },
        { id: 'name_en', label: t('promotions::admin_coupons.form.name_en'), value: form.data.name_en, rules: { required: true, length: { max: 100 } } },
        { id: 'percent', label: t('promotions::admin_coupons.form.percent'), value: form.data.percent, off: !percent, rules: { required: true, number: { decimals: 0, min: 1, max: 100 } } },
        { id: 'cap', label: t('promotions::admin_coupons.form.cap'), value: form.data.cap, off: !percent, rules: { number: money } },
        { id: 'amount', label: t('promotions::admin_coupons.form.amount'), value: form.data.amount, off: percent, rules: { required: true, number: money } },
        { id: 'minimum', label: t('promotions::admin_coupons.form.minimum'), value: form.data.minimum, rules: { number: money } },
        { id: 'max_uses_per_customer', label: t('promotions::admin_coupons.form.uses_per_customer'), value: form.data.max_uses_per_customer, rules: { number: { decimals: 0, min: 1 } } },
        { id: 'max_uses_total', label: t('promotions::admin_coupons.form.uses_total'), value: form.data.max_uses_total, rules: { number: { decimals: 0, min: 1 } } },
        momentBox(starts, { id: 'starts', label: t('promotions::admin_coupons.form.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'ends', label: t('promotions::admin_coupons.form.ends'), kind: 'end', zone, after: starts }),
    ]);
    const [momentsTouched, setMomentsTouched] = useState(false);
    const changed = form.isDirty || momentsTouched;
    // A coupon its customers have stays assigned to them while they have it (answer 25).
    const lockedAssigned = coupon !== null && coupon.eligibility === 'ASSIGNED' && coupon.assignedCount > 0;

    // A moment left as it was saved goes back as the server sent it, its seconds kept: a save of any other
    // field never moves the start by the seconds the box does not show (the review).
    const sent = (input: MomentInput, saved: string | null): string | null => (saved !== null && isSaved(input) ? saved : isoOf(input, zone));

    function save() {
        // Enter in a box submits the form too: an unchanged one sends nothing, as its button says.
        if (!changed || readOnly) {
            return;
        }

        checks.submit(() => {
            form.transform((data) => ({ ...data, starts_at: sent(starts, coupon?.startsAt ?? null), ends_at: sent(ends, coupon?.endsAt ?? null), rules: data.rules.map(({ kind, target, id }) => ({ kind, target, id })) }));
            const done = { preserveScroll: true, onSuccess: () => setMomentsTouched(false) };

            if (coupon === null) {
                form.post('/admin/coupons', done);
            } else {
                form.post(`/admin/coupons/${coupon.id}`, done);
            }
        });
    }

    const add = (kind: 'INCLUDE' | 'EXCLUDE') => (picked: Picked) => form.setData('rules', [...form.data.rules, { kind, target: picked.target, id: picked.id, name: picked.name, path: picked.path, sold: true }]);
    const remove = (rule: Rule) => form.setData('rules', form.data.rules.filter((each) => !(each.kind === rule.kind && each.target === rule.target && each.id === rule.id)));
    const taken = (kind: 'INCLUDE' | 'EXCLUDE') => new Set(form.data.rules.filter((rule) => rule.kind === kind).map((rule) => `${rule.target}:${rule.id}`));
    const digits = (key: keyof FormData) => (event: React.ChangeEvent<HTMLInputElement>) => form.setData(key, toLatinDigits(event.target.value) as never);

    return (
        <form
            className="grid gap-6"
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
        >
            <fieldset disabled={readOnly || form.processing} className="grid gap-6">
                <Section title={t('promotions::admin_coupons.form.section_code')}>
                    {coupon === null ? (
                        <TextField
                            id="code"
                            label={t('promotions::admin_coupons.form.code')}
                            helper={t('promotions::admin_coupons.form.code_hint')}
                            check={checks.box('code', errors.code)}
                            dir="ltr"
                            inputClassName="font-mono"
                            required
                            value={form.data.code}
                            onChange={(event) => form.setData('code', toLatinDigits(event.target.value).toUpperCase())}
                        />
                    ) : (
                        <div className="grid gap-1" data-test="code-fixed">
                            <p className="text-label-14 text-ink">{t('promotions::admin_coupons.form.code')}</p>
                            <bdi dir="ltr" className="font-mono text-label-14 text-ink">
                                {coupon.code}
                            </bdi>
                            <p className="text-copy-13 text-ink-muted">{t('promotions::admin_coupons.form.code_fixed')}</p>
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField id="name_ar" label={t('promotions::admin_coupons.form.name_ar')} dir="rtl" check={checks.box('name_ar', errors.name_ar ?? errors.name)} required value={form.data.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} />
                        <TextField id="name_en" label={t('promotions::admin_coupons.form.name_en')} dir="ltr" check={checks.box('name_en', errors.name_en ?? errors.name)} required value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} />
                    </div>
                </Section>

                <Section title={t('promotions::admin_coupons.form.section_who')}>
                    <Choice
                        name="eligibility"
                        legend={t('promotions::admin_coupons.form.eligibility')}
                        value={form.data.eligibility}
                        onChange={(value) => form.setData('eligibility', value)}
                        disabled={lockedAssigned}
                        reason={lockedAssigned ? t('promotions::admin_coupons.form.eligibility_locked') : undefined}
                        error={errors.eligibility}
                        options={[
                            { value: 'PUBLIC', label: t('promotions::admin_coupons.eligibility.PUBLIC') },
                            { value: 'ASSIGNED', label: t('promotions::admin_coupons.eligibility.ASSIGNED') },
                        ]}
                    />
                </Section>

                <Section title={t('promotions::admin_coupons.form.section_what')}>
                    <Choice
                        name="discount_kind"
                        legend={t('promotions::admin_coupons.form.discount_kind')}
                        value={form.data.discount_kind}
                        onChange={(value) => form.setData('discount_kind', value)}
                        error={errors.discount_kind}
                        options={[
                            { value: 'PERCENT', label: t('promotions::admin_coupons.form.kind_percent') },
                            { value: 'FIXED', label: t('promotions::admin_coupons.form.kind_fixed') },
                        ]}
                    />
                    <div className="grid gap-4 sm:grid-cols-3">
                        {percent ? (
                            <>
                                <TextField id="percent" label={t('promotions::admin_coupons.form.percent')} inputMode="numeric" dir="ltr" check={checks.box('percent', errors.percent)} required value={form.data.percent} onChange={digits('percent')} />
                                <TextField id="cap" label={t('promotions::admin_coupons.form.cap', { currency: page.currency })} helper={t('promotions::admin_coupons.form.optional')} inputMode="decimal" dir="ltr" check={checks.box('cap', errors.cap)} value={form.data.cap} onChange={digits('cap')} />
                            </>
                        ) : (
                            <TextField id="amount" label={t('promotions::admin_coupons.form.amount', { currency: page.currency })} inputMode="decimal" dir="ltr" check={checks.box('amount', errors.amount)} required value={form.data.amount} onChange={digits('amount')} />
                        )}
                        <TextField id="minimum" label={t('promotions::admin_coupons.form.minimum', { currency: page.currency })} helper={t('promotions::admin_coupons.form.optional')} inputMode="decimal" dir="ltr" check={checks.box('minimum', errors.minimum)} value={form.data.minimum} onChange={digits('minimum')} />
                    </div>
                    {/* The store's ceiling, said where the discount is set (owner's Q15). */}
                    <p className="text-copy-13 text-ink-muted" data-test="ceiling">
                        {t('promotions::admin_coupons.form.ceiling', { percent: isolate(`${figure(locale, page.ceilingPercent)}%`) })}
                    </p>
                </Section>

                <Section title={t('promotions::admin_coupons.form.section_when')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <DateTimeField
                            id="starts"
                            label={t('promotions::admin_coupons.form.starts')}
                            kind="start"
                            value={starts}
                            onChange={(next) => {
                                setStarts(next);
                                setMomentsTouched(true);
                            }}
                            zone={zone}
                            check={checks.box('starts-time', errors.starts_at ?? errors.dates)}
                            disabled={readOnly}
                        />
                        <DateTimeField
                            id="ends"
                            label={t('promotions::admin_coupons.form.ends')}
                            kind="end"
                            value={ends}
                            onChange={(next) => {
                                setEnds(next);
                                setMomentsTouched(true);
                            }}
                            zone={zone}
                            after={starts}
                            check={checks.box('ends-time', errors.ends_at)}
                            disabled={readOnly}
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField id="max_uses_per_customer" label={t('promotions::admin_coupons.form.uses_per_customer')} placeholder={t('promotions::admin_coupons.form.no_limit')} inputMode="numeric" dir="ltr" check={checks.box('max_uses_per_customer', errors.max_uses_per_customer ?? errors.max_uses)} value={form.data.max_uses_per_customer} onChange={digits('max_uses_per_customer')} />
                        <TextField id="max_uses_total" label={t('promotions::admin_coupons.form.uses_total')} placeholder={t('promotions::admin_coupons.form.no_limit')} inputMode="numeric" dir="ltr" check={checks.box('max_uses_total', errors.max_uses_total ?? errors.max_uses)} value={form.data.max_uses_total} onChange={digits('max_uses_total')} />
                    </div>
                </Section>

                <Section title={t('promotions::admin_coupons.form.section_where')}>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Toggle id="for_retail" label={t('promotions::admin_coupons.form.for_retail')} checked={form.data.for_retail} onChange={(on) => form.setData('for_retail', on)} />
                        <Toggle id="for_wholesale" label={t('promotions::admin_coupons.form.for_wholesale')} checked={form.data.for_wholesale} onChange={(on) => form.setData('for_wholesale', on)} />
                        <Toggle id="for_public" label={t('promotions::admin_coupons.form.for_public')} checked={form.data.for_public} onChange={(on) => form.setData('for_public', on)} />
                        <Toggle id="for_company" label={t('promotions::admin_coupons.form.for_company')} checked={form.data.for_company} onChange={(on) => form.setData('for_company', on)} />
                        <Toggle id="on_reduced_lines" label={t('promotions::admin_coupons.form.on_reduced_lines')} hint={t('promotions::admin_coupons.form.on_reduced_lines_hint')} checked={form.data.on_reduced_lines} onChange={(on) => form.setData('on_reduced_lines', on)} />
                        <Toggle id="allow_with_points" label={t('promotions::admin_coupons.form.allow_with_points')} hint={t('promotions::admin_coupons.form.allow_with_points_hint')} checked={form.data.allow_with_points} onChange={(on) => form.setData('allow_with_points', on)} />
                    </div>
                    {errors.sale_modes ?? errors.buyers ? (
                        <Note variant="error" alert>
                            {errors.sale_modes ?? errors.buyers}
                        </Note>
                    ) : null}
                </Section>

                <Section title={t('promotions::admin_coupons.form.section_covers')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {(['INCLUDE', 'EXCLUDE'] as const).map((kind) => (
                            <div key={kind} className="grid content-start gap-2" data-test={`rules-${kind}`}>
                                <h3 className="text-heading-14 text-ink">{t(`promotions::admin_coupons.form.${kind === 'INCLUDE' ? 'includes' : 'excludes'}`)}</h3>
                                <div className="flex flex-wrap gap-2">
                                    {form.data.rules.filter((rule) => rule.kind === kind).length === 0 ? (
                                        <span className="text-copy-13 text-ink-muted">{t(`promotions::admin_coupons.form.${kind === 'INCLUDE' ? 'every_product' : 'nothing_excluded'}`)}</span>
                                    ) : null}
                                    {form.data.rules
                                        .filter((rule) => rule.kind === kind)
                                        .map((rule) => (
                                            <Badge key={`${rule.target}:${rule.id}`} variant="outline" className="gap-1.5 py-1 pe-1" data-test={`rule-${rule.target}-${rule.id}`}>
                                                <span className="text-ink-muted">{t('promotions::admin_coupons.form.rule_target', { target: t(`promotions::admin_coupons.target.${rule.target}`) })}</span>
                                                {rule.path ?? rule.name ?? t('promotions::admin_coupons.form.gone')}
                                                {/* Still on the list, matching nothing (answer 22): said beside its name. */}
                                                {rule.sold ? null : (
                                                    <span className="text-copy-12 text-warn" data-test="not-sold">
                                                        {t('promotions::admin_coupons.form.not_sold')}
                                                    </span>
                                                )}
                                                {readOnly ? null : (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-xs"
                                                        aria-label={t('promotions::admin_coupons.form.remove_rule', { name: rule.path ?? rule.name ?? t('promotions::admin_coupons.form.gone') })}
                                                        onClick={() => remove(rule)}
                                                    >
                                                        <X aria-hidden="true" />
                                                    </Button>
                                                )}
                                            </Badge>
                                        ))}
                                </div>
                                {readOnly ? null : (
                                    <div>
                                        <Button type="button" variant="outline" size="sm" onClick={() => setPicking(kind)} data-test={`add-${kind}`}>
                                            {`${t('promotions::admin_coupons.form.add_rule')}…`}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                    {errors.rules ? (
                        <Note variant="error" alert>
                            {errors.rules}
                        </Note>
                    ) : null}
                </Section>
            </fieldset>

            {readOnly ? null : (
                <div className="flex justify-end">
                    <ActionButton type="submit" loading={form.processing} disabledReason={changed ? checks.reason : t('promotions::admin_coupons.form.nothing_changed')} data-test="save-coupon">
                        {coupon === null ? t('promotions::admin_coupons.form.create') : t('promotions::admin_coupons.form.save')}
                    </ActionButton>
                </div>
            )}

            {picking === null ? null : (
                <Suspense fallback={null}>
                    <RulePicker
                        open
                        onOpenChange={(open) => (open ? undefined : setPicking(null))}
                        title={picking === 'EXCLUDE' ? t('promotions::admin_coupons.picker.title_exclude') : t('promotions::admin_coupons.picker.title_include')}
                        categories={page.categories}
                        brands={page.brands}
                        products={page.products}
                        taken={taken(picking)}
                        onPick={add(picking)}
                    />
                </Suspense>
            )}
        </form>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <Card className="material-base gap-4 border-0 py-5">
            <CardHeader className="px-5">
                <CardTitle className="text-heading-16 text-ink">
                    <h2>{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 px-5">{children}</CardContent>
        </Card>
    );
}
