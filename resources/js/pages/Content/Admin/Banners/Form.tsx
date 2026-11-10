import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { ImageBox } from '@/components/content/image-box';
import { sentMoment } from '@/components/content/moments';
import { StateBadge } from '@/components/content/list';
import { PairFields } from '@/components/content/parts';
import { useReadTick, useTranslation } from '@/components/content/translate';
import { DateTimeField } from '@/components/DateTimeField';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { useLeaveGuard } from '@/components/SaveBar';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { useInLanguage } from '@/lib/in-language';
import { momentBox, momentFrom, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { BannerFormPageData, ContentImageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W3 · Add Banner and a banner's page (content.md §1.6, §4.4): placement (chosen once), name, where a listing banner
| shows, the words tick, each image its placement takes with its size note, link, description per language, dates, and
| Schedule Banner or Save Draft. Scheduling waits for the images' sizes (§1.1 #10): the refusal names the image.
*/

const LIST = '/admin/content/banners';

/** The sizes to upload (§1.6), by placement and kind. */
const SIZES: Record<string, Partial<Record<'DESKTOP' | 'PHONE', { width: number; height: number }>>> = {
    HOME_HERO: { DESKTOP: { width: 1600, height: 1040 } },
    HOME_PHONE: { PHONE: { width: 1200, height: 600 } },
    LISTING: { DESKTOP: { width: 1600, height: 300 }, PHONE: { width: 1200, height: 300 } },
};

/** The slots a banner takes (BannerRules::slots): `{DESKTOP|PHONE}:{any|ar|en}`. */
function slotsOf(placement: string, hasWords: boolean): string[] {
    const kinds = Object.keys(SIZES[placement] ?? {});

    return kinds.flatMap((kind) => (hasWords ? ['ar', 'en'] : ['any']).map((locale) => `${kind}:${locale}`));
}

/** A slot's box, as the server names it in a refusal (BannerRules::box). */
function boxOf(slot: string): string {
    const [kind = '', locale = 'any'] = slot.split(':');

    return `${kind.toLowerCase()}_image${locale === 'any' ? '' : `_${locale}`}`;
}

export default function Form({ store, storeTimezone, placement: asked, banner, images: savedImages, categories, translationReady }: BannerFormPageData) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const zone = storeTimezone;
    const form = useForm({
        placement: banner?.placement ?? asked,
        name: banner?.name ?? '',
        category_id: banner?.categoryId ?? '',
        has_words: banner?.hasWords ?? false,
        link: banner?.link ?? '',
        alt_ar: banner?.altAr ?? '',
        alt_en: banner?.altEn ?? '',
    });
    const [onCategory, setOnCategory] = useState(banner?.categoryId != null);
    const [images, setImages] = useState<Record<string, ContentImageData>>(savedImages);
    const [starts, setStarts] = useState<MomentInput>(() => momentFrom(banner?.startsAt ?? null, zone));
    const [ends, setEnds] = useState<MomentInput>(() => momentFrom(banner?.endsAt ?? null, zone));
    const [touched, setTouched] = useState(false);
    const translation = useTranslation('banner', store.id, translationReady);
    const tick = useReadTick(translation);
    const state = banner?.state ?? 'DRAFT';
    const scheduled = state === 'SCHEDULED' || state === 'LIVE';
    const slots = slotsOf(form.data.placement, form.data.has_words);
    const checks = useChecks([
        { id: 'name', label: t('content::admin_banners.fields.name'), value: form.data.name, rules: { required: true, length: { max: 100 } } },
        { id: 'alt_ar', label: t('content::admin_banners.fields.alt_ar'), value: form.data.alt_ar, rules: { required: true, length: { max: 200 } } },
        { id: 'alt_en', label: t('content::admin_banners.fields.alt_en'), value: form.data.alt_en, rules: { required: scheduled, length: { max: 200 } } },
        { id: 'category_id', label: t('content::admin_banners.fields.category'), value: form.data.category_id, rules: { required: form.data.placement === 'LISTING' && onCategory } },
        momentBox(starts, { id: 'starts', label: t('content::admin_shared.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'ends', label: t('content::admin_shared.ends'), kind: 'end', zone, after: starts }),
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const leave = useLeaveGuard(form.isDirty || touched, { description: t('content::admin_shared.leave') });
    const labels: Record<string, string> = {
        desktop_image: t('content::admin_banners.fields.desktop_image'),
        desktop_image_ar: t('content::admin_banners.fields.desktop_image_ar'),
        desktop_image_en: t('content::admin_banners.fields.desktop_image_en'),
        phone_image: t('content::admin_banners.fields.phone_image'),
        phone_image_ar: t('content::admin_banners.fields.phone_image_ar'),
        phone_image_en: t('content::admin_banners.fields.phone_image_en'),
    };
    const placements: Record<string, string> = {
        HOME_HERO: t('content::admin_banners.placement.HOME_HERO'),
        HOME_PHONE: t('content::admin_banners.placement.HOME_PHONE'),
        LISTING: t('content::admin_banners.placement.LISTING'),
    };

    const send = (intent: 'draft' | 'schedule') => {
        form.transform((data) => ({
            ...data,
            category_id: data.placement === 'LISTING' && onCategory ? data.category_id : '',
            store: store.id,
            version: banner?.version ?? 0,
            images: Object.fromEntries(slots.map((slot) => [slot, images[slot]?.id ?? ''])),
            starts_at: sentMoment(starts),
            ends_at: sentMoment(ends),
            intent,
        }));
        form.post(banner === null ? LIST : `${LIST}/${banner.id}`, { preserveScroll: 'errors' });
    };
    const scheduleLabel = scheduled ? t('content::admin_banners.save') : t('content::admin_banners.schedule');

    return (
        <AdminLayout title={banner === null ? t('content::admin_banners.add') : banner.name} breadcrumbs={[{ label: t('content::admin_banners.title'), href: `${LIST}?store=${encodeURIComponent(store.code)}&placement=${form.data.placement}` }]}>
            <form
                className="grid max-w-4xl min-w-0 gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    checks.submit(() => tick.ask(() => send('schedule'), scheduleLabel));
                }}
                data-test="banner-form"
            >
                <FormError />
                <div className="flex flex-wrap items-center gap-2 text-copy-14 text-ink-muted">
                    <span>{`${t('content::admin_shared.store')}: ${store.name}`}</span>
                    {banner === null ? null : <StateBadge state={state} />}
                </div>
                {banner === null ? (
                    <SelectField id="placement" className="max-w-xs" label={t('content::admin_banners.placement_label')} error={errors.placement} value={form.data.placement} onChange={(event) => form.setData('placement', event.target.value)} data-test="placement">
                        {Object.entries(placements).map(([value, label]) => (
                            <NativeSelectOption key={value} value={value}>
                                {label}
                            </NativeSelectOption>
                        ))}
                    </SelectField>
                ) : (
                    <p className="text-copy-14 text-ink">{`${t('content::admin_banners.placement_label')}: ${placements[form.data.placement]}`}</p>
                )}
                <TextField id="name" label={t('content::admin_banners.fields.name')} helper={t('content::admin_banners.fields.name_helper')} check={checks.box('name', errors.name)} value={form.data.name} onChange={(event) => form.setData('name', toLatinDigits(event.target.value))} data-test="name" />

                {form.data.placement === 'LISTING' ? (
                    <FieldSet className="gap-3">
                        <FieldLegend variant="label" className="text-label-14 text-ink">
                            {t('content::admin_banners.fields.target')}
                        </FieldLegend>
                        <RadioGroup value={onCategory ? 'CATEGORY' : 'ALL'} onValueChange={(next) => setOnCategory(next === 'CATEGORY')}>
                            <Field orientation="horizontal">
                                <RadioGroupItem id="target-ALL" value="ALL" data-test="target-ALL" />
                                <FieldLabel htmlFor="target-ALL" className="font-normal">
                                    {t('content::admin_banners.fields.on_all')}
                                </FieldLabel>
                            </Field>
                            <Field orientation="horizontal">
                                <RadioGroupItem id="target-CATEGORY" value="CATEGORY" data-test="target-CATEGORY" />
                                <FieldLabel htmlFor="target-CATEGORY" className="font-normal">
                                    {t('content::admin_banners.fields.on_category')}
                                </FieldLabel>
                            </Field>
                        </RadioGroup>
                        {onCategory ? (
                            // A native select of the paths, the build's pick: §4.4 W3 names no picker, and the search box's code
                            // would take this page over its 60 KB (JavaScriptBudgetTest).
                            <SelectField
                                id="category_id"
                                className="max-w-md"
                                label={t('content::admin_banners.fields.category')}
                                check={checks.box('category_id', errors.category ?? errors.category_id)}
                                value={form.data.category_id}
                                onChange={(event) => form.setData('category_id', event.target.value)}
                                data-test="category"
                            >
                                <NativeSelectOption value="">{t('content::admin_banners.fields.category')}</NativeSelectOption>
                                {categories.map((each) => (
                                    <NativeSelectOption key={each.id} value={each.id}>
                                        {inLanguage(each.pathAr, each.pathEn)}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>
                        ) : null}
                    </FieldSet>
                ) : null}

                <Field orientation="horizontal">
                    <Checkbox id="has_words" checked={form.data.has_words} onCheckedChange={(on) => form.setData('has_words', on === true)} data-test="has-words" />
                    <div className="grid min-w-0 gap-1">
                        <FieldLabel htmlFor="has_words" className="font-normal">
                            {t('content::admin_banners.fields.has_words')}
                        </FieldLabel>
                        <FieldDescription>{t('content::admin_banners.fields.has_words_helper')}</FieldDescription>
                    </div>
                </Field>

                <div className="grid gap-5 sm:grid-cols-2">
                    {slots.map((slot) => {
                        const box = boxOf(slot);
                        const kind = slot.split(':')[0] as 'DESKTOP' | 'PHONE';

                        return (
                            <ImageBox
                                key={slot}
                                id={box}
                                label={labels[box] ?? box}
                                helper={form.data.placement === 'HOME_HERO' ? t('content::admin_banners.fields.hero_note') : undefined}
                                kind="banner"
                                store={store.id}
                                image={images[slot] ?? null}
                                onChange={(next) => {
                                    setTouched(true);
                                    setImages((was) => {
                                        const copy = { ...was };

                                        if (next === null) {
                                            delete copy[slot];
                                        } else {
                                            copy[slot] = next;
                                        }

                                        return copy;
                                    });
                                }}
                                size={SIZES[form.data.placement]?.[kind]}
                                error={errors[box] ?? (errors.images && slots[0] === slot ? errors.images : undefined)}
                            />
                        );
                    })}
                </div>

                <TextField id="link" dir="ltr" label={t('content::admin_shared.link')} helper={t('content::admin_shared.link_helper')} error={errors.link} value={form.data.link} onChange={(event) => form.setData('link', toLatinDigits(event.target.value))} data-test="link" />
                <PairFields
                    name="alt"
                    labelAr={t('content::admin_banners.fields.alt_ar')}
                    labelEn={t('content::admin_banners.fields.alt_en')}
                    helper={t('content::admin_banners.fields.alt_rule')}
                    ar={form.data.alt_ar}
                    en={form.data.alt_en}
                    onChange={(language, value) => form.setData(language === 'ar' ? 'alt_ar' : 'alt_en', value)}
                    errors={errors}
                    checks={checks}
                    translation={translation}
                />
                <div className="grid gap-4 sm:grid-cols-2" data-test="dates">
                    <DateTimeField
                        id="starts"
                        label={t('content::admin_shared.starts')}
                        kind="start"
                        value={starts}
                        onChange={(next) => {
                            setStarts(next);
                            setTouched(true);
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
                            setTouched(true);
                        }}
                        zone={zone}
                        check={checks.box('ends', errors.ends_at)}
                    />
                </div>
                <div className="flex flex-wrap gap-3">
                    <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="schedule">
                        {scheduleLabel}
                    </ActionButton>
                    {scheduled ? null : (
                        <ActionButton type="button" variant="outline" loading={form.processing} onClick={() => checks.submit(() => send('draft'))} data-test="save-draft">
                            {t('content::admin_banners.save_draft')}
                        </ActionButton>
                    )}
                </div>
            </form>
            {translation.dialog}
            {tick.dialog}
            {leave}
        </AdminLayout>
    );
}
