import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { sentMoment } from '@/components/content/moments';
import { PairFields } from '@/components/content/parts';
import { useReadTick, useTranslation } from '@/components/content/translate';
import { DateTimeField } from '@/components/DateTimeField';
import { TextField } from '@/components/Fields';
import { useLeaveGuard } from '@/components/SaveBar';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { toLatinDigits } from '@/lib/digits';
import { momentBox, momentFrom, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { BarsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/* W2's announcement bar (content.md §1.5): on or off, its text per language, its link and its dates. */

const LIST = '/admin/content/bars';

export function AnnouncementForm({ storeId, zone, bar, translationReady }: { storeId: string; zone: string; bar: BarsPageData['bar']; translationReady: boolean }) {
    const t = useTranslator();
    const form = useForm({ text_ar: bar.textAr ?? '', text_en: bar.textEn ?? '', link: bar.link ?? '', is_on: bar.isOn });
    const [starts, setStarts] = useState<MomentInput>(() => momentFrom(bar.startsAt, zone));
    const [ends, setEnds] = useState<MomentInput>(() => momentFrom(bar.endsAt, zone));
    const [datesTouched, setDatesTouched] = useState(false);
    const translation = useTranslation('bar', storeId, translationReady);
    const tick = useReadTick(translation);
    const checks = useChecks([
        { id: 'text_ar', label: t('content::admin_bars.bar.text_ar'), value: form.data.text_ar, rules: { required: form.data.is_on, length: { max: 120 } } },
        { id: 'text_en', label: t('content::admin_bars.bar.text_en'), value: form.data.text_en, rules: { required: form.data.is_on, length: { max: 120 } } },
        momentBox(starts, { id: 'starts', label: t('content::admin_shared.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'ends', label: t('content::admin_shared.ends'), kind: 'end', zone, after: starts }),
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const leave = useLeaveGuard(form.isDirty || datesTouched, { description: t('content::admin_shared.leave') });

    const send = () => {
        form.transform((data) => ({ ...data, store: storeId, version: bar.version, starts_at: sentMoment(starts), ends_at: sentMoment(ends) }));
        form.post(`${LIST}/announcement`, { preserveScroll: true, onSuccess: () => setDatesTouched(false) });
    };

    return (
        <form
            className="grid max-w-4xl gap-6"
            onSubmit={(event) => {
                event.preventDefault();
                checks.submit(() => (form.data.is_on ? tick.ask(send, t('content::admin_bars.bar.save')) : send()));
            }}
            data-test="bar-form"
        >
            <Field orientation="horizontal">
                <Switch id="bar-on" checked={form.data.is_on} onCheckedChange={(on) => form.setData('is_on', on)} data-test="bar-on" />
                <div className="grid min-w-0 gap-1">
                    <FieldLabel htmlFor="bar-on">{t('content::admin_bars.bar.on')}</FieldLabel>
                    <FieldDescription>{t('content::admin_bars.bar.on_helper')}</FieldDescription>
                </div>
            </Field>
            <PairFields
                name="text"
                labelAr={t('content::admin_bars.bar.text_ar')}
                labelEn={t('content::admin_bars.bar.text_en')}
                helper={t('content::admin_bars.bar.text_rule')}
                ar={form.data.text_ar}
                en={form.data.text_en}
                onChange={(language, value) => form.setData(language === 'ar' ? 'text_ar' : 'text_en', value)}
                errors={errors}
                checks={checks}
                translation={translation}
            />
            <TextField
                id="link"
                dir="ltr"
                label={t('content::admin_shared.link')}
                helper={t('content::admin_shared.link_helper')}
                error={errors.link}
                value={form.data.link}
                onChange={(event) => form.setData('link', toLatinDigits(event.target.value))}
                data-test="link"
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <DateTimeField
                    id="starts"
                    label={t('content::admin_shared.starts')}
                    kind="start"
                    value={starts}
                    onChange={(next) => {
                        setStarts(next);
                        setDatesTouched(true);
                    }}
                    zone={zone}
                    check={checks.box('starts', errors.starts_at)}
                />
                <DateTimeField
                    id="ends"
                    label={t('content::admin_shared.ends')}
                    kind="end"
                    value={ends}
                    after={starts}
                    onChange={(next) => {
                        setEnds(next);
                        setDatesTouched(true);
                    }}
                    zone={zone}
                    check={checks.box('ends', errors.ends_at)}
                />
            </div>
            <div>
                <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="save-bar">
                    {t('content::admin_bars.bar.save')}
                </ActionButton>
            </div>
            {translation.dialog}
            {tick.dialog}
            {leave}
        </form>
    );
}
