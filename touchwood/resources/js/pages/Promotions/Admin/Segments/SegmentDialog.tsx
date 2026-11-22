import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Choice } from '@/components/promotions/fields';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { SegmentRowData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| New Segment… and Edit… (promotions.md §4.1 M3): the name in both languages; on a new segment its type
| and who it is for - fixed once made (answer 23), said under the choices; for a rule segment its rule
| and the rule's numbers. Edit… changes the names and the numbers only.
*/

const RULES = ['NO_ORDERS_EVER', 'NO_ORDER_FOR', 'AT_LEAST_ORDERS'] as const;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    storeCode: string;
    /** The segment edited; none for a new one. */
    segment?: SegmentRowData;
};

export function SegmentDialog({ open, onOpenChange, storeCode, segment }: Props) {
    const t = useTranslator();
    const form = useForm({
        store: storeCode,
        name_ar: segment?.nameAr ?? '',
        name_en: segment?.nameEn ?? '',
        segment_type: segment?.type ?? 'DYNAMIC',
        audience: segment?.audience ?? 'INDIVIDUAL',
        rule: segment?.rule ?? 'NO_ORDER_FOR',
        rule_months: segment?.ruleMonths === null || segment?.ruleMonths === undefined ? '' : String(segment.ruleMonths),
        rule_orders: segment?.ruleOrders === null || segment?.ruleOrders === undefined ? '' : String(segment.ruleOrders),
    });
    const dynamic = form.data.segment_type === 'DYNAMIC';
    const months = dynamic && form.data.rule !== 'NO_ORDERS_EVER';
    const orders = dynamic && form.data.rule === 'AT_LEAST_ORDERS';
    const errors: Partial<Record<string, string>> = form.errors;
    const checks = useChecks(
        [
            { id: 'name_ar', label: t('promotions::admin_segments.form.name_ar'), value: form.data.name_ar, rules: { required: true, length: { max: 100 } } },
            { id: 'name_en', label: t('promotions::admin_segments.form.name_en'), value: form.data.name_en, rules: { required: true, length: { max: 100 } } },
            { id: 'rule_months', label: t('promotions::admin_segments.form.months'), value: form.data.rule_months, off: !months, rules: { required: true, number: { decimals: 0, min: 1, max: 120 } } },
            { id: 'rule_orders', label: t('promotions::admin_segments.form.orders'), value: form.data.rule_orders, off: !orders, rules: { required: true, number: { decimals: 0, min: 1, max: 1000 } } },
        ],
        open,
    );

    function save() {
        checks.submit(() => {
            // Only what the type and rule take is sent: the server refuses numbers a rule has none of.
            form.transform((data) => ({
                ...data,
                rule: dynamic ? data.rule : '',
                rule_months: months ? data.rule_months : '',
                rule_orders: orders ? data.rule_orders : '',
            }));
            const done = {
                preserveScroll: true,
                // The saved values become the form's own (Inertia keeps the page's state): no reset here, or
                // the next Edit… would open on the names from before (the review).
                onSuccess: () => onOpenChange(false),
            };

            if (segment === undefined) {
                form.post('/admin/segments', done);
            } else {
                form.post(`/admin/segments/${segment.id}`, done);
            }
        });
    }

    const digits = (key: 'rule_months' | 'rule_orders') => (event: React.ChangeEvent<HTMLInputElement>) => form.setData(key, toLatinDigits(event.target.value));

    return (
        <PanelDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.reset();
                    form.clearErrors();
                }
                onOpenChange(next);
            }}
            title={segment === undefined ? t('promotions::admin_segments.form.new_title') : t('promotions::admin_segments.form.edit_title')}
            description={segment === undefined ? t('promotions::admin_segments.form.new_description') : t('promotions::admin_segments.form.edit_description')}
            busy={form.processing}
            confirm={
                <ActionButton type="button" loading={form.processing} disabledReason={checks.reason} onClick={save} data-test="save-segment">
                    {segment === undefined ? t('promotions::admin_segments.form.create') : t('promotions::admin_segments.form.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField id="name_ar" label={t('promotions::admin_segments.form.name_ar')} dir="rtl" required check={checks.box('name_ar', errors.name_ar ?? errors.name)} value={form.data.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} />
                    <TextField id="name_en" label={t('promotions::admin_segments.form.name_en')} dir="ltr" required check={checks.box('name_en', errors.name_en ?? errors.name)} value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} />
                </div>

                {segment === undefined ? (
                    <>
                        <Choice
                            name="segment_type"
                            legend={t('promotions::admin_segments.form.type')}
                            value={form.data.segment_type}
                            onChange={(value) => form.setData('segment_type', value)}
                            options={[
                                { value: 'DYNAMIC', label: t('promotions::admin_segments.type.DYNAMIC') },
                                { value: 'STATIC', label: t('promotions::admin_segments.type.STATIC') },
                            ]}
                            error={errors.segment_type}
                        />
                        <Choice
                            name="audience"
                            legend={t('promotions::admin_segments.form.audience')}
                            value={form.data.audience}
                            onChange={(value) => form.setData('audience', value)}
                            options={[
                                { value: 'INDIVIDUAL', label: t('promotions::admin_segments.audience.INDIVIDUAL') },
                                { value: 'COMPANY', label: t('promotions::admin_segments.audience.COMPANY') },
                            ]}
                            reason={t('promotions::admin_segments.form.fixed')}
                            error={errors.audience}
                        />
                    </>
                ) : null}

                {dynamic ? (
                    <Choice
                        name="rule"
                        legend={t('promotions::admin_segments.form.rule')}
                        value={form.data.rule}
                        onChange={(value) => form.setData('rule', value)}
                        options={RULES.map((rule) => ({ value: rule, label: t(`promotions::admin_segments.rule_choice.${rule}`) }))}
                        error={errors.rule}
                    />
                ) : null}

                {months || orders ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {orders ? (
                            <TextField id="rule_orders" label={t('promotions::admin_segments.form.orders')} inputMode="numeric" dir="ltr" required check={checks.box('rule_orders', errors.rule_orders)} value={form.data.rule_orders} onChange={digits('rule_orders')} />
                        ) : null}
                        <TextField id="rule_months" label={t('promotions::admin_segments.form.months')} inputMode="numeric" dir="ltr" required check={checks.box('rule_months', errors.rule_months)} value={form.data.rule_months} onChange={digits('rule_months')} />
                    </div>
                ) : null}
            </div>
        </PanelDialog>
    );
}
