import { useState, type RefObject } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { sentMoment } from '@/components/content/moments';
import { PairFields } from '@/components/content/parts';
import { useReadTick, useTranslation } from '@/components/content/translate';
import { DateTimeField } from '@/components/DateTimeField';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchCombobox } from '@/components/SearchCombobox';
import { Field, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { toLatinDigits } from '@/lib/digits';
import { useInLanguage } from '@/lib/in-language';
import { momentBox, momentFrom, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { CategoryChoiceData, SmartBarData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/* W2's smart bar dialog (content.md §1.9), loaded when it opens: never on the page's first paint. */

const LIST = '/admin/content/bars';

export function SmartBarDialog({
    row,
    storeId,
    zone,
    categories,
    translationReady,
    returnFocusTo,
    onClose,
}: {
    row: SmartBarData | null;
    storeId: string;
    zone: string;
    categories: CategoryChoiceData[];
    translationReady: boolean;
    /** The row's own control, where the focus goes back once the dialog closes. */
    returnFocusTo: RefObject<HTMLElement | null>;
    onClose: () => void;
}) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const form = useForm({
        message_ar: row?.messageAr ?? '',
        message_en: row?.messageEn ?? '',
        scope: row?.scope ?? 'CATEGORY',
        category_id: row?.categoryId ?? '',
        link: row?.link ?? '',
    });
    const [starts, setStarts] = useState<MomentInput>(() => momentFrom(row?.startsAt ?? null, zone));
    const [ends, setEnds] = useState<MomentInput>(() => momentFrom(row?.endsAt ?? null, zone));
    const translation = useTranslation('bar', storeId, translationReady);
    const tick = useReadTick(translation);
    const checks = useChecks([
        { id: 'message_ar', label: t('content::admin_bars.smart.message_ar'), value: form.data.message_ar, rules: { required: true, length: { max: 100 } } },
        { id: 'message_en', label: t('content::admin_bars.smart.message_en'), value: form.data.message_en, rules: { required: true, length: { max: 100 } } },
        { id: 'category_id', label: t('content::admin_bars.smart.category'), value: form.data.category_id, rules: { required: form.data.scope === 'CATEGORY' } },
        momentBox(starts, { id: 'smart-starts', label: t('content::admin_shared.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'smart-ends', label: t('content::admin_shared.ends'), kind: 'end', zone, after: starts }),
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const label = t('content::admin_bars.smart.save');

    const send = () => {
        form.transform((data) => ({ ...data, store: storeId, starts_at: sentMoment(starts), ends_at: sentMoment(ends) }));
        form.post(row === null ? `${LIST}/smart` : `${LIST}/smart/${row.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <PanelDialog
            wide
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            returnFocusTo={returnFocusTo}
            title={row === null ? t('content::admin_bars.smart.add') : t('content::admin_bars.smart.edit')}
            description={t('content::admin_bars.smart.empty_body')}
            busy={form.processing}
            confirm={
                <ActionButton type="button" loading={form.processing} disabledReason={checks.reason} onClick={() => checks.submit(() => tick.ask(send, label))} data-test="save-smart-bar">
                    {label}
                </ActionButton>
            }
        >
            <div className="grid gap-5" data-test="smart-bar-form">
                {errors.form ? (
                    <p role="alert" className="text-copy-14 text-bad">
                        {errors.form}
                    </p>
                ) : null}
                <PairFields
                    name="message"
                    labelAr={t('content::admin_bars.smart.message_ar')}
                    labelEn={t('content::admin_bars.smart.message_en')}
                    helper={t('content::admin_bars.smart.message_rule')}
                    ar={form.data.message_ar}
                    en={form.data.message_en}
                    onChange={(language, value) => form.setData(language === 'ar' ? 'message_ar' : 'message_en', value)}
                    errors={errors}
                    checks={checks}
                    translation={translation}
                />
                <FieldSet className="gap-3">
                    <FieldLegend variant="label" className="text-label-14 text-ink">
                        {t('content::admin_bars.smart.shown_on')}
                    </FieldLegend>
                    <RadioGroup value={form.data.scope} onValueChange={(next) => form.setData('scope', next)}>
                        <Field orientation="horizontal">
                            <RadioGroupItem id="scope-CATEGORY" value="CATEGORY" data-test="scope-CATEGORY" />
                            <FieldLabel htmlFor="scope-CATEGORY" className="font-normal">
                                {t('content::admin_bars.smart.on_category')}
                            </FieldLabel>
                        </Field>
                        <Field orientation="horizontal" data-disabled>
                            <RadioGroupItem id="scope-CAMPAIGN" value="CAMPAIGN" disabled aria-describedby="scope-campaign-why" data-test="scope-CAMPAIGN" />
                            <div className="grid gap-1">
                                <FieldLabel htmlFor="scope-CAMPAIGN" className="font-normal">
                                    {t('content::admin_bars.smart.on_campaign')}
                                </FieldLabel>
                                <FieldDescription id="scope-campaign-why">{t('content::admin_bars.smart.campaign_none')}</FieldDescription>
                            </div>
                        </Field>
                    </RadioGroup>
                </FieldSet>
                {form.data.scope === 'CATEGORY' ? (
                    <SearchCombobox
                        id="category_id"
                        label={t('content::admin_bars.smart.category')}
                        helper={t('content::admin_bars.smart.category_helper')}
                        error={checks.box('category_id', errors.category_id).message}
                        options={categories.map((each) => ({ value: each.id, label: inLanguage(each.pathAr, each.pathEn) }))}
                        value={form.data.category_id}
                        onChange={(next) => form.setData('category_id', next)}
                        words={{ search: t('content::admin_bars.smart.category_search'), none: (query) => t('content::admin_bars.smart.category_none', { query }) }}
                        data-test="category"
                    />
                ) : null}
                <TextField
                    id="smart-link"
                    dir="ltr"
                    label={t('content::admin_shared.link')}
                    helper={t('content::admin_shared.link_helper')}
                    error={errors.link}
                    value={form.data.link}
                    onChange={(event) => form.setData('link', toLatinDigits(event.target.value))}
                    data-test="smart-link"
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <DateTimeField id="smart-starts" label={t('content::admin_shared.starts')} kind="start" value={starts} onChange={setStarts} zone={zone} check={checks.box('smart-starts', errors.starts_at)} />
                    <DateTimeField id="smart-ends" label={t('content::admin_shared.ends')} kind="end" value={ends} after={starts} onChange={setEnds} zone={zone} check={checks.box('smart-ends', errors.ends_at)} />
                </div>
            </div>
            {translation.dialog}
            {tick.dialog}
        </PanelDialog>
    );
}
