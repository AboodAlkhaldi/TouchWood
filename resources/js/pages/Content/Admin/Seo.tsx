import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { ImageBox } from '@/components/content/image-box';
import { PairFields } from '@/components/content/parts';
import { TranslateAllButton, useReadTick, useTranslation } from '@/components/content/translate';
import { TextareaField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { MiddleTruncate } from '@/components/geist-only/MiddleTruncate';
import { Note } from '@/components/Note';
import { useLeaveGuard } from '@/components/SaveBar';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { AdminLayout } from '@/layouts/AdminLayout';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ContentImageData, SeoPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| W10 · SEO (content.md §4.4, §1.14): the store's defaults per language - site name, title pattern with a sample drawn,
| description, share image - its extra robots lines, and its sitemap: last built, how many addresses, its address to
| copy, Rebuild Sitemap ("Queued", out of reach while one waits). Search Console isn't touched by the system.
*/

type Pairs = 'site_name' | 'title_pattern' | 'description';

export default function Seo(page: SeoPageData) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const store = page.stores.find((each) => each.code === page.storeCode);
    const storeId = store?.id ?? '';
    const form = useForm({
        site_name_ar: page.siteNameAr ?? '',
        site_name_en: page.siteNameEn ?? '',
        title_pattern_ar: page.titlePatternAr ?? '',
        title_pattern_en: page.titlePatternEn ?? '',
        description_ar: page.descriptionAr ?? '',
        description_en: page.descriptionEn ?? '',
        robots_extra: page.robotsLines,
    });
    const [image, setImage] = useState<ContentImageData | null>(page.shareImage);
    const [queueing, setQueueing] = useState(false);
    const translation = useTranslation('seo', storeId, page.translationReady);
    const tick = useReadTick(translation);
    const checks = useChecks([
        { id: 'site_name_ar', label: t('content::admin_seo.site_name_ar'), value: form.data.site_name_ar, rules: { required: true, length: { max: 60 } } },
        { id: 'site_name_en', label: t('content::admin_seo.site_name_en'), value: form.data.site_name_en, rules: { required: true, length: { max: 60 } } },
        { id: 'title_pattern_ar', label: t('content::admin_seo.title_pattern_ar'), value: form.data.title_pattern_ar, rules: { required: true, length: { max: 80 } } },
        { id: 'title_pattern_en', label: t('content::admin_seo.title_pattern_en'), value: form.data.title_pattern_en, rules: { required: true, length: { max: 80 } } },
        { id: 'description_ar', label: t('content::admin_seo.description_ar'), value: form.data.description_ar, rules: { length: { max: 160 } } },
        { id: 'description_en', label: t('content::admin_seo.description_en'), value: form.data.description_en, rules: { required: form.data.description_ar !== '', length: { max: 160 } } },
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const leave = useLeaveGuard(form.isDirty || image?.id !== page.shareImage?.id, { description: t('content::admin_shared.leave') });
    const set = (name: Pairs) => (language: 'ar' | 'en', value: string) => form.setData(`${name}_${language}`, value);
    const pairs = (['site_name', 'title_pattern', 'description'] as const).map((name) => ({ name, ar: form.data[`${name}_ar`], en: form.data[`${name}_en`], fill: (value: string) => form.setData(`${name}_en`, value) }));
    const sample = (pattern: string, site: string, title: string) => pattern.replaceAll(':title', title).replaceAll(':site', site);
    const shownSample = locale === 'ar' ? sample(form.data.title_pattern_ar, form.data.site_name_ar, t('content::admin_seo.sample_title_ar')) : sample(form.data.title_pattern_en, form.data.site_name_en, t('content::admin_seo.sample_title'));
    const label = t('content::admin_seo.save');

    const send = () => {
        form.transform((data) => ({ ...data, store: storeId, share_media_id: image?.id ?? '' }));
        form.post('/admin/content/seo', { preserveScroll: true });
    };

    return (
        <AdminLayout title={t('content::admin_seo.title')} subtitle={t('content::admin_seo.subtitle')}>
            <div className="grid min-w-0 grid-cols-1 gap-6">
                <FormError />
                <StoreFilter stores={page.stores.map((each) => ({ code: each.code, name: each.name, isActive: each.isOn }))} value={page.storeCode} className="max-w-xs" />
                <form
                    className="grid max-w-4xl min-w-0 gap-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        checks.submit(() => tick.ask(send, label));
                    }}
                    data-test="seo-form"
                >
                    <Note size="small">{t('content::admin_seo.defaults_helper')}</Note>
                    <TranslateAllButton translation={translation} pairs={pairs} />
                    <PairFields name="site_name" labelAr={t('content::admin_seo.site_name_ar')} labelEn={t('content::admin_seo.site_name_en')} helper={t('content::admin_seo.site_name_rule')} ar={form.data.site_name_ar} en={form.data.site_name_en} onChange={set('site_name')} errors={errors} checks={checks} translation={translation} />
                    <PairFields name="title_pattern" labelAr={t('content::admin_seo.title_pattern_ar')} labelEn={t('content::admin_seo.title_pattern_en')} helper={t('content::admin_seo.title_pattern_rule')} ar={form.data.title_pattern_ar} en={form.data.title_pattern_en} onChange={set('title_pattern')} errors={errors} checks={checks} translation={translation} />
                    {shownSample.trim() === '' ? null : (
                        <p className="min-w-0 text-copy-14 break-words text-ink-muted" data-test="pattern-sample">
                            {t('content::admin_seo.sample', { sample: shownSample })}
                        </p>
                    )}
                    <PairFields name="description" rows={3} labelAr={t('content::admin_seo.description_ar')} labelEn={t('content::admin_seo.description_en')} helper={t('content::admin_seo.description_rule')} ar={form.data.description_ar} en={form.data.description_en} onChange={set('description')} errors={errors} checks={checks} translation={translation} />
                    <ImageBox id="share_image" label={t('content::admin_seo.share_image')} helper={t('content::admin_seo.share_image_helper')} kind="seo" store={storeId} image={image} onChange={setImage} size={{ width: 1200, height: 630 }} error={errors.share_image} />
                    <TextareaField
                        id="robots_extra"
                        dir="ltr"
                        rows={5}
                        label={t('content::admin_seo.robots')}
                        helper={t('content::admin_seo.robots_helper')}
                        error={errors.robots_extra}
                        value={form.data.robots_extra}
                        onChange={(event) => form.setData('robots_extra', toLatinDigits(event.target.value))}
                        data-test="robots"
                    />
                    <div>
                        <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="save-seo">
                            {label}
                        </ActionButton>
                    </div>
                </form>

                <section className="material-base grid max-w-4xl min-w-0 gap-3 p-4" aria-labelledby="sitemap-title" data-test="sitemap">
                    <h2 id="sitemap-title" className="text-heading-16 text-ink">
                        {t('content::admin_seo.sitemap.title')}
                    </h2>
                    <dl className="grid gap-2 text-copy-14 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <dt className="text-ink-muted">{t('content::admin_seo.sitemap.built')}</dt>
                        <dd className="text-ink" data-test="built">
                            {page.sitemapBuiltAt === null ? t('content::admin_seo.sitemap.never') : <Time value={page.sitemapBuiltAt} mode="absolute" />}
                        </dd>
                        <dt className="text-ink-muted">{t('content::admin_seo.sitemap.urls')}</dt>
                        <dd className="tw-figure text-ink" data-test="urls">
                            {figure(locale, page.sitemapUrls)}
                        </dd>
                        <dt className="text-ink-muted">{t('content::admin_seo.sitemap.address')}</dt>
                        <dd className="flex min-w-0 items-center gap-2" dir="ltr">
                            <MiddleTruncate value={page.sitemapAddress} className="min-w-0 font-mono text-copy-13" />
                            <CopyButton text={page.sitemapAddress} label={t('content::admin_seo.sitemap.copy')} />
                        </dd>
                    </dl>
                    <div>
                        <ActionButton
                            type="button"
                            variant="outline"
                            loading={queueing}
                            disabledReason={page.rebuildWaits ? t('content::admin_seo.sitemap.waits_reason') : undefined}
                            onClick={() => router.post('/admin/content/seo/sitemap', { store: storeId }, { preserveScroll: true, onStart: () => setQueueing(true), onFinish: () => setQueueing(false) })}
                            data-test="rebuild"
                        >
                            {page.rebuildWaits ? t('content::admin_seo.sitemap.waits') : t('content::admin_seo.sitemap.rebuild')}
                        </ActionButton>
                    </div>
                    <p className="text-copy-13 text-ink-muted">{t('content::admin_seo.sitemap.console')}</p>
                </section>
            </div>
            {translation.dialog}
            {tick.dialog}
            {leave}
        </AdminLayout>
    );
}
