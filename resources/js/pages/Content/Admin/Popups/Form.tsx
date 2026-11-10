import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { ImageBox } from '@/components/content/image-box';
import { sentMoment } from '@/components/content/moments';
import { PairFields, StateBadge } from '@/components/content/parts';
import { TranslateAllButton, useReadTick, useTranslation } from '@/components/content/translate';
import { DateTimeField } from '@/components/DateTimeField';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { useLeaveGuard } from '@/components/SaveBar';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { momentBox, momentFrom, type MomentInput } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ContentImageData, PopupFormPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| W6 · New Pop-Up and a pop-up's page (content.md §1.10, §4.4): its fields, a Preview drawing it at phone and computer
| size, Save Draft and Schedule Pop-Up - or Save Changes once scheduled or live. A clash with another pop-up comes back
| under the dates. Schedule asks for the one tick while Translate's mark stays on any English box (§1.3).
*/

const LIST = '/admin/content/popups';

export default function Form({ store, storeTimezone, popup, image: savedImage, translationReady }: PopupFormPageData) {
    const t = useTranslator();
    const zone = storeTimezone;
    const form = useForm({
        name: popup?.name ?? '',
        title_ar: popup?.titleAr ?? '',
        title_en: popup?.titleEn ?? '',
        text_ar: popup?.textAr ?? '',
        text_en: popup?.textEn ?? '',
        button_ar: popup?.buttonAr ?? '',
        button_en: popup?.buttonEn ?? '',
        link: popup?.link ?? '',
        delay_seconds: String(popup?.delaySeconds ?? 8),
    });
    const [image, setImage] = useState<ContentImageData | null>(savedImage);
    const [starts, setStarts] = useState<MomentInput>(() => momentFrom(popup?.startsAt ?? null, zone));
    const [ends, setEnds] = useState<MomentInput>(() => momentFrom(popup?.endsAt ?? null, zone));
    const [touched, setTouched] = useState(false);
    const translation = useTranslation('popup', store.id, translationReady);
    const tick = useReadTick(translation);
    const state = popup?.state ?? 'DRAFT';
    const scheduled = state === 'SCHEDULED' || state === 'LIVE';
    const checks = useChecks([
        { id: 'name', label: t('content::admin_popups.fields.name'), value: form.data.name, rules: { required: true, length: { max: 100 } } },
        { id: 'title_ar', label: t('content::admin_popups.fields.title_ar'), value: form.data.title_ar, rules: { required: true, length: { max: 80 } } },
        { id: 'title_en', label: t('content::admin_popups.fields.title_en'), value: form.data.title_en, rules: { required: scheduled, length: { max: 80 } } },
        { id: 'text_ar', label: t('content::admin_popups.fields.text_ar'), value: form.data.text_ar, rules: { required: true, length: { max: 240 } } },
        { id: 'text_en', label: t('content::admin_popups.fields.text_en'), value: form.data.text_en, rules: { required: scheduled, length: { max: 240 } } },
        { id: 'button_ar', label: t('content::admin_popups.fields.button_ar'), value: form.data.button_ar, rules: { length: { max: 40 } } },
        { id: 'button_en', label: t('content::admin_popups.fields.button_en'), value: form.data.button_en, rules: { length: { max: 40 } } },
        { id: 'delay_seconds', label: t('content::admin_popups.fields.delay'), value: form.data.delay_seconds, rules: { required: true, number: { min: 3, max: 60 } } },
        momentBox(starts, { id: 'starts', label: t('content::admin_shared.starts'), kind: 'start', zone }),
        momentBox(ends, { id: 'ends', label: t('content::admin_shared.ends'), kind: 'end', zone, after: starts }),
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const leave = useLeaveGuard(form.isDirty || touched || image?.id !== savedImage?.id, { description: t('content::admin_shared.leave') });
    const pairs = (['title', 'text', 'button'] as const).map((name) => ({
        name,
        ar: form.data[`${name}_ar`],
        en: form.data[`${name}_en`],
        fill: (value: string) => form.setData(`${name}_en`, value),
    }));

    const send = (intent: 'draft' | 'schedule') => {
        form.transform((data) => ({ ...data, store: store.id, version: popup?.version ?? 0, media_id: image?.id ?? '', starts_at: sentMoment(starts), ends_at: sentMoment(ends), intent }));
        form.post(popup === null ? LIST : `${LIST}/${popup.id}`, { preserveScroll: 'errors' });
    };
    const set = (name: 'title' | 'text' | 'button') => (language: 'ar' | 'en', value: string) => form.setData(`${name}_${language}`, value);
    const scheduleLabel = scheduled ? t('content::admin_popups.save') : t('content::admin_popups.schedule');

    return (
        <AdminLayout title={popup === null ? t('content::admin_popups.new') : popup.name} breadcrumbs={[{ label: t('content::admin_popups.title'), href: `${LIST}?store=${encodeURIComponent(store.code)}` }]}>
            <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <form
                    className="grid min-w-0 content-start gap-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        checks.submit(() => tick.ask(() => send('schedule'), scheduleLabel));
                    }}
                    data-test="popup-form"
                >
                    <FormError />
                    <div className="flex flex-wrap items-center gap-2 text-copy-14 text-ink-muted">
                        <span>{`${t('content::admin_shared.store')}: ${store.name}`}</span>
                        {popup === null ? null : <StateBadge state={state} />}
                    </div>
                    <TextField
                        id="name"
                        label={t('content::admin_popups.fields.name')}
                        helper={t('content::admin_popups.fields.name_helper')}
                        check={checks.box('name', errors.name)}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', toLatinDigits(event.target.value))}
                        data-test="name"
                    />
                    <TranslateAllButton translation={translation} pairs={pairs} />
                    <PairFields name="title" labelAr={t('content::admin_popups.fields.title_ar')} labelEn={t('content::admin_popups.fields.title_en')} helper={t('content::admin_popups.fields.title_rule')} ar={form.data.title_ar} en={form.data.title_en} onChange={set('title')} errors={errors} checks={checks} translation={translation} />
                    <PairFields name="text" rows={4} labelAr={t('content::admin_popups.fields.text_ar')} labelEn={t('content::admin_popups.fields.text_en')} helper={t('content::admin_popups.fields.text_rule')} ar={form.data.text_ar} en={form.data.text_en} onChange={set('text')} errors={errors} checks={checks} translation={translation} />
                    <PairFields name="button" labelAr={t('content::admin_popups.fields.button_ar')} labelEn={t('content::admin_popups.fields.button_en')} helper={t('content::admin_popups.fields.button_rule')} ar={form.data.button_ar} en={form.data.button_en} onChange={set('button')} errors={errors} checks={checks} translation={translation} />
                    <TextField id="link" dir="ltr" label={t('content::admin_shared.link')} helper={t('content::admin_shared.link_helper')} error={errors.link} value={form.data.link} onChange={(event) => form.setData('link', toLatinDigits(event.target.value))} data-test="link" />
                    <TextField
                        id="delay_seconds"
                        inputMode="numeric"
                        dir="ltr"
                        className="max-w-48"
                        label={t('content::admin_popups.fields.delay')}
                        helper={t('content::admin_popups.fields.delay_helper')}
                        check={checks.box('delay_seconds', errors.delay_seconds)}
                        value={form.data.delay_seconds}
                        onChange={(event) => form.setData('delay_seconds', toLatinDigits(event.target.value))}
                        data-test="delay"
                    />
                    <ImageBox
                        id="image"
                        label={t('content::admin_popups.fields.image')}
                        helper={t('content::admin_popups.fields.image_helper')}
                        kind="popup"
                        store={store.id}
                        image={image}
                        onChange={setImage}
                        size={{ width: 840, height: 440 }}
                        error={errors.image}
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
                                {t('content::admin_popups.save_draft')}
                            </ActionButton>
                        )}
                    </div>
                </form>
                <PopupPreview title={{ ar: form.data.title_ar, en: form.data.title_en }} text={{ ar: form.data.text_ar, en: form.data.text_en }} button={{ ar: form.data.button_ar, en: form.data.button_en }} image={image} />
            </div>
            {translation.dialog}
            {tick.dialog}
            {leave}
        </AdminLayout>
    );
}

type Words = { ar: string; en: string };

/**
 * The pop-up as shoppers will see it (§1.10), in the page's language: on a phone a sheet from the bottom with no image,
 * on a computer a dialog 420 wide with it - drawn small, here, beside the form (under it on a phone).
 */
function PopupPreview({ title, text, button, image }: { title: Words; text: Words; button: Words; image: ContentImageData | null }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const pick = (words: Words) => (locale === 'ar' ? words.ar : words.en || words.ar);
    const dir = locale === 'ar' || title.en === '' ? 'rtl' : 'ltr';
    const body = (withImage: boolean) => (
        <div dir={dir} className="material-base grid gap-3 bg-surface p-4">
            <div className="flex items-start justify-between gap-2">
                <p className="min-w-0 text-heading-16 break-words text-ink">{pick(title)}</p>
                <X className="size-4 shrink-0 text-ink-muted" aria-label={t('content::admin_popups.preview.close')} />
            </div>
            {withImage && image?.thumbnail ? <img src={image.thumbnail} alt="" className="aspect-[21/11] w-full rounded-md object-cover" /> : null}
            <p className="text-copy-14 break-words text-ink-muted">{pick(text)}</p>
            {pick(button) === '' ? null : (
                <Button type="button" size="sm" tabIndex={-1} className="w-full">
                    {pick(button)}
                </Button>
            )}
        </div>
    );

    return (
        <section className="grid content-start gap-3" aria-labelledby="popup-preview" data-test="popup-preview">
            <h2 id="popup-preview" className="text-heading-16 text-ink">
                {t('content::admin_popups.preview.title')}
            </h2>
            <Tabs defaultValue="phone">
                <TabsList variant="line">
                    <TabsTrigger value="phone" data-test="preview-phone">
                        {t('content::admin_popups.preview.phone')}
                    </TabsTrigger>
                    <TabsTrigger value="desktop" data-test="preview-desktop">
                        {t('content::admin_popups.preview.desktop')}
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="phone" className="pt-3">
                    <div className="mx-auto grid h-80 w-48 content-end overflow-hidden rounded-xl bg-surface-sunken p-1" aria-hidden>
                        <div className="max-h-[40%] overflow-y-auto">{body(false)}</div>
                    </div>
                </TabsContent>
                <TabsContent value="desktop" className="pt-3">
                    <div className="grid place-items-center rounded-xl bg-surface-sunken p-4" aria-hidden>
                        <div className="w-full max-w-[420px]">{body(true)}</div>
                    </div>
                </TabsContent>
            </Tabs>
        </section>
    );
}
