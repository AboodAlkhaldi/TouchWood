import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { ImageBox } from '@/components/content/image-box';
import { PairFields } from '@/components/content/parts';
import { TranslateAllButton, useReadTick, useTranslation, type Pair } from '@/components/content/translate';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { useLeaveGuard } from '@/components/SaveBar';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldLegend, FieldSet } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ContentImageData, HomeSectionPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { SortableList } from '../../../Catalog/Admin/SortableList';

/*
| The logos band's own page (content.md §1.4 #8, §4.4 W1): its heading and its logos - each an image (360 x 156 PNG),
| its name per language as its description, an optional link - dragged into order, 6 to 40. Its own page and form, apart
| from the other types' (section-form.tsx), so only it carries the dragging code (JavaScriptBudgetTest).
*/

type Words = { ar: string; en: string };
type Logo = { key: string; image: ContentImageData | null; mediaId: string; name: Words; link: string };

let next = 0;

export default function Logos({ store, section, logos: saved, translationReady }: HomeSectionPageData) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props as unknown as { errors: Record<string, string | undefined> };
    const settings = (Array.isArray(section.settings) ? {} : section.settings) as { heading?: { ar?: string | null; en?: string | null } };
    const [heading, setHeading] = useState<Words>({ ar: settings.heading?.ar ?? '', en: settings.heading?.en ?? '' });
    const [logos, setLogos] = useState<Logo[]>(() => saved.map((logo) => ({ key: `logo-${++next}`, image: logo.image, mediaId: logo.mediaId, name: { ar: logo.nameAr, en: logo.nameEn ?? '' }, link: logo.link ?? '' })));
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [adding, setAdding] = useState(0);
    const translation = useTranslation('homepage', store.id, translationReady);
    const tick = useReadTick(translation);
    const checks = useChecks([
        { id: 'heading_ar', label: t('content::admin_home.fields.arabic', { field: t('content::admin_home.fields.heading') }), value: heading.ar, rules: { length: { max: 80 } } },
        { id: 'heading_en', label: t('content::admin_home.fields.english', { field: t('content::admin_home.fields.heading') }), value: heading.en, rules: { required: section.isVisible && heading.ar !== '', length: { max: 80 } } },
    ]);
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const change = (apply: () => void) => {
        apply();
        setDirty(true);
    };
    const setLogo = (index: number, logo: Logo) => change(() => setLogos((was) => was.map((each, i) => (i === index ? logo : each))));
    const pairs: Pair[] = [
        { name: 'heading', ar: heading.ar, en: heading.en, fill: (english) => change(() => setHeading((was) => ({ ...was, en: english }))) },
        ...logos.map((logo, index) => ({ name: `logo${index}`, ar: logo.name.ar, en: logo.name.en, fill: (english: string) => setLogo(index, { ...logo, name: { ...logo.name, en: english } }) })),
    ];
    const label = t('content::admin_home.section.save');
    const name = { ar: t('content::admin_home.fields.arabic', { field: t('content::admin_home.fields.logo_name') }), en: t('content::admin_home.fields.english', { field: t('content::admin_home.fields.logo_name') }) };

    const send = () =>
        router.post(
            `/admin/content/home/${section.id}`,
            { store: store.id, version: section.version, settings: { heading }, logos: logos.map((logo) => ({ mediaId: logo.mediaId, nameAr: logo.name.ar, nameEn: logo.name.en, link: logo.link })) },
            { preserveScroll: 'errors', onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: () => setDirty(false) },
        );

    return (
        <AdminLayout title={t('content::admin_home.type.CLIENT_LOGOS')} breadcrumbs={[{ label: t('content::admin_home.title'), href: `/admin/content/home?store=${encodeURIComponent(store.code)}` }]}>
            <form
                className="grid max-w-4xl min-w-0 gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    checks.submit(() => (section.isVisible ? tick.ask(send, label) : send()));
                }}
                data-test="section-form"
            >
                <FormError />
                <Note size="small">{section.isVisible ? t('content::admin_home.section.shown_note') : t('content::admin_home.section.hidden_note')}</Note>
                <TranslateAllButton translation={translation} pairs={pairs} />
                <PairFields
                    name="heading"
                    labelAr={t('content::admin_home.fields.arabic', { field: t('content::admin_home.fields.heading') })}
                    labelEn={t('content::admin_home.fields.english', { field: t('content::admin_home.fields.heading') })}
                    ar={heading.ar}
                    en={heading.en}
                    onChange={(language, typed) => change(() => setHeading((was) => ({ ...was, [language]: typed })))}
                    errors={errors}
                    checks={checks}
                    translation={translation}
                />
                <FieldSet className="gap-4" data-test="logos">
                    <FieldLegend variant="label" className="text-label-14 text-ink">
                        {t('content::admin_home.fields.logos')}
                    </FieldLegend>
                    <FieldDescription>{t('content::admin_home.fields.logos_helper')}</FieldDescription>
                    {errors.logos || errors.logo || errors.logo_name_en ? <p className="text-copy-14 text-bad">{errors.logos ?? errors.logo ?? errors.logo_name_en}</p> : null}
                    <SortableList
                        testPrefix="logo"
                        items={logos.map((logo, index) => ({
                            id: logo.key,
                            label: logo.name.ar || `${t('content::admin_home.fields.logo')} ${index + 1}`,
                            content: (
                                <div className="grid min-w-0 flex-1 gap-3 py-2">
                                    <ImageBox
                                        id={`logo${index}image`}
                                        label={t('content::admin_home.fields.logo')}
                                        kind="homepage"
                                        store={store.id}
                                        image={logo.image}
                                        size={{ width: 360, height: 156, png: true }}
                                        onChange={(image) => (image === null ? undefined : setLogo(index, { ...logo, image, mediaId: image.id }))}
                                    />
                                    <PairFields name={`logo${index}`} labelAr={name.ar} labelEn={name.en} ar={logo.name.ar} en={logo.name.en} onChange={(language, typed) => setLogo(index, { ...logo, name: { ...logo.name, [language]: typed } })} errors={{}} checks={checks} translation={translation} />
                                    <TextField id={`logo${index}link`} dir="ltr" label={t('content::admin_shared.link')} value={logo.link} onChange={(event) => setLogo(index, { ...logo, link: toLatinDigits(event.target.value) })} />
                                </div>
                            ),
                            extra: (
                                <Button type="button" variant="ghost" size="sm" onClick={() => change(() => setLogos((was) => was.filter((_, i) => i !== index)))}>
                                    <Trash2 aria-hidden />
                                    {t('content::admin_home.fields.remove')}
                                </Button>
                            ),
                        }))}
                        onChange={(ids) => change(() => setLogos((was) => ids.flatMap((id) => was.filter((logo) => logo.key === id))))}
                    />
                    {logos.length < 40 ? (
                        // A new logo starts from its file: the box uploads it, and the logo joins the list with its name to type.
                        <ImageBox
                            key={adding}
                            id="new-logo"
                            label={t('content::admin_home.fields.add_logo')}
                            kind="homepage"
                            store={store.id}
                            image={null}
                            size={{ width: 360, height: 156, png: true }}
                            onChange={(image) => {
                                if (image !== null) {
                                    change(() => setLogos((was) => [...was, { key: `logo-${++next}`, image, mediaId: image.id, name: { ar: '', en: '' }, link: '' }]));
                                    setAdding((was) => was + 1);
                                }
                            }}
                        />
                    ) : null}
                </FieldSet>
                <div>
                    <ActionButton type="submit" loading={saving} disabledReason={checks.reason} data-test="save-section">
                        {label}
                    </ActionButton>
                </div>
            </form>
            {translation.dialog}
            {tick.dialog}
            {leave}
        </AdminLayout>
    );
}
