import { lazy, Suspense, useRef, useState, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { RichTextEditor } from '@/components/content/editor';
import { ImageBox } from '@/components/content/image-box';
import { StateBadge } from '@/components/content/list';
import { isShopLink, PairFields } from '@/components/content/parts';
import { markFields, TranslateAllButton, TranslateButton, useReadTick, useTranslation, type Pair, type Translation } from '@/components/content/translate';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { MoreButton } from '@/components/RowMenu';
import { useLeaveGuard } from '@/components/SaveBar';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks, type Box, type Checks } from '@/lib/use-checks';
import type { ContentImageData, DocumentFormPageData, ProductChoiceData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W7-W9's form (content.md §4.4, §1.11-§1.14), one for pages, posts and projects: title and address per language (the
| address made from the title until typed, the whole of it shown), the body in the text editor with Translate under its
| English, Search and Sharing in a `Collapsible` (title 70, description 160, each with its count, the default as its
| placeholder; a share image per language), and what the kind adds (a post's summary, cover and category, a project's
| city and photos, their products), drawn by the page. **Publish** or **Save Draft**; once published, **Save Changes**;
| ⋯ Unpublish… while published, Delete… while a draft, each naming first where Content's links point at it.
|
| Publish and a published item's Save Changes ask the one tick while Translate's mark stays on (§1.3), and the mark
| outlives the session (P14): an item saved with it opens marked.
*/

const ScheduleConfirm = lazy(() => import('./schedule-confirm').then((module) => ({ default: module.ScheduleConfirm })));

export type Lang = 'ar' | 'en';
type Words = { ar: string; en: string };

/** The kind's own words, every key written out whole on its page (TranslationKeysTest). */
export type DocumentWords = {
    list: string;
    new: string;
    item: string;
    publish: string;
    unpublishMenu: string;
    unpublishTitle: string;
    unpublishBody: string;
    unpublishConfirm: string;
    deleteMenu: string;
    deleteTitle: string;
    deleteBody: string;
    deleteConfirm: string;
};

/** What the page's own fields read and change. */
export type DocumentState = {
    form: ReturnType<typeof useDocumentData>;
    errors: Record<string, string | undefined>;
    checks: Checks;
    translation: Translation;
    set: (name: 'summary' | 'cover_alt' | 'city') => (language: Lang, value: string) => void;
    cover: ContentImageData | null;
    setCover: (image: ContentImageData | null) => void;
    photos: ContentImageData[];
    setPhotos: (photos: ContentImageData[]) => void;
    products: ProductChoiceData[];
    setProducts: (products: ProductChoiceData[]) => void;
    uploadKind: string;
    storeId: string;
};

function useDocumentData(page: DocumentFormPageData) {
    const document = page.document;
    const text = (language: Lang) => (language === 'ar' ? document?.ar : document?.en);
    const words = (key: 'title' | 'slug' | 'body' | 'seoTitle' | 'seoDescription' | 'summary' | 'coverAlt' | 'city', language: Lang) => text(language)?.[key] ?? '';

    return useForm({
        title_ar: words('title', 'ar'),
        title_en: words('title', 'en'),
        slug_ar: words('slug', 'ar'),
        slug_en: words('slug', 'en'),
        body_ar: words('body', 'ar'),
        body_en: words('body', 'en'),
        seo_title_ar: words('seoTitle', 'ar'),
        seo_title_en: words('seoTitle', 'en'),
        seo_description_ar: words('seoDescription', 'ar'),
        seo_description_en: words('seoDescription', 'en'),
        summary_ar: words('summary', 'ar'),
        summary_en: words('summary', 'en'),
        cover_alt_ar: words('coverAlt', 'ar'),
        cover_alt_en: words('coverAlt', 'en'),
        city_ar: words('city', 'ar'),
        city_en: words('city', 'en'),
        category_id: document?.categoryId ?? '',
    });
}

export type DocumentFields = ReturnType<typeof useDocumentData>['data'];

export function DocumentForm({ page, words, children, extraChecks }: { page: DocumentFormPageData; words: DocumentWords; children?: (state: DocumentState) => ReactNode; extraChecks?: (data: DocumentFields, published: boolean) => Box[] }) {
    const t = useTranslator();
    const { store, kind, document } = page;
    const uploadKind = kind === 'post' ? 'blog' : kind;
    const form = useDocumentData(page);
    const imageOf = (id: string | null) => (id === null ? null : (page.images[id] ?? null));
    const bodyImages = (language: Lang) => Object.fromEntries(Object.entries((language === 'ar' ? document?.ar : document?.en)?.images ?? {}).flatMap(([ref, id]) => (page.images[id] ? [[Number(ref), page.images[id]]] : [])));
    const [images, setImages] = useState<Record<Lang, Record<number, ContentImageData>>>(() => ({ ar: bodyImages('ar'), en: bodyImages('en') }));
    const [share, setShare] = useState<Record<Lang, ContentImageData | null>>(() => ({ ar: imageOf(document?.shareAr ?? null), en: imageOf(document?.shareEn ?? null) }));
    const [cover, setCover] = useState<ContentImageData | null>(() => imageOf(document?.coverMediaId ?? null));
    const [photos, setPhotos] = useState<ContentImageData[]>(() => (document?.photos ?? []).flatMap((id) => (page.images[id] ? [page.images[id]] : [])));
    const [products, setProducts] = useState<ProductChoiceData[]>(page.products);
    const [touched, setTouched] = useState(false);
    const [asking, setAsking] = useState<'unpublish' | 'delete' | null>(null);
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const translation = useTranslation(uploadKind, store.id, page.translationReady, document?.translationUnread === true);
    const tick = useReadTick(translation);
    const published = document?.status === 'PUBLISHED';
    // Published, an item keeps English beside every Arabic (§1.1 rule 2): its boxes are checked so as they are typed.
    const english = (ar: string) => published && ar.trim() !== '';
    const checks = useChecks([
        { id: 'title_ar', label: t('content::admin_documents.fields.title_ar'), value: form.data.title_ar, rules: { required: true, length: { max: 150 } } },
        { id: 'title_en', label: t('content::admin_documents.fields.title_en'), value: form.data.title_en, rules: { required: published, length: { max: 150 } } },
        { id: 'seo_title_ar', label: t('content::admin_documents.fields.seo_title_ar'), value: form.data.seo_title_ar, rules: { length: { max: 70 } } },
        { id: 'seo_title_en', label: t('content::admin_documents.fields.seo_title_en'), value: form.data.seo_title_en, rules: { required: english(form.data.seo_title_ar), length: { max: 70 } } },
        { id: 'seo_description_ar', label: t('content::admin_documents.fields.seo_description_ar'), value: form.data.seo_description_ar, rules: { length: { max: 160 } } },
        { id: 'seo_description_en', label: t('content::admin_documents.fields.seo_description_en'), value: form.data.seo_description_en, rules: { required: english(form.data.seo_description_ar), length: { max: 160 } } },
        ...(extraChecks?.(form.data, published) ?? []),
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const dirty = form.isDirty || touched;
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const set = (name: string) => (language: Lang, value: string) => form.setData(`${name}_${language}` as 'title_ar', value);
    const changed = (apply: () => void) => {
        apply();
        setTouched(true);
    };
    const pairsOf = (names: string[]): Pair[] => names.map((name) => ({ name, ar: form.data[`${name}_ar` as 'title_ar'], en: form.data[`${name}_en` as 'title_en'], fill: (value: string) => form.setData(`${name}_en` as 'title_en', value) }));
    const own = kind === 'post' ? ['summary', 'cover_alt'] : kind === 'project' ? ['city'] : [];
    const bodyPair: Pair = { name: 'body', ar: form.data.body_ar, en: form.data.body_en, rich: true, fill: (value) => form.setData('body_en', value) };
    const pairs = [...pairsOf(['title', ...own, 'seo_title', 'seo_description']), bodyPair];
    const base = kind === 'page' ? '/admin/content/pages' : kind === 'post' ? '/admin/content/blog/posts' : '/admin/content/projects';
    const address = (language: Lang) => `${page.shopAddress}/${language}${page.pathPrefix}${form.data[`slug_${language}`] || t('content::admin_documents.fields.address_from_title')}`;
    const places = Object.entries(page.linkedFrom).map(([place, count]) => {
        const named: Record<string, string> = {
            footer: t('content::admin_documents.places.footer'),
            home_section: t('content::admin_documents.places.home_section'),
            banner: t('content::admin_documents.places.banner'),
            bar: t('content::admin_documents.places.bar'),
            smart_bar: t('content::admin_documents.places.smart_bar'),
            popup: t('content::admin_documents.places.popup'),
        };

        return count > 1 ? `${named[place] ?? place} (${count})` : (named[place] ?? place);
    });
    const linksNote = places.length === 0 ? '' : ` ${t('content::admin_documents.links_note', { item: words.item, places: places.join(' · ') })}`;
    const mainLabel = published ? t('content::admin_documents.save') : words.publish;

    const send = (intent: 'draft' | 'publish', read = false) => {
        form.transform((data) => ({
            ...data,
            ...markFields(translation, read),
            store: store.id,
            version: document?.version ?? 0,
            images_ar: Object.fromEntries(Object.entries(images.ar).map(([ref, image]) => [ref, image.id])),
            images_en: Object.fromEntries(Object.entries(images.en).map(([ref, image]) => [ref, image.id])),
            share_ar: share.ar?.id ?? '',
            share_en: share.en?.id ?? '',
            cover_media_id: cover?.id ?? '',
            photos: photos.map((photo) => photo.id),
            products: products.map((product) => product.id),
            intent,
        }));
        form.post(document === null ? base : `${base}/${document.id}`, { preserveScroll: 'errors', onSuccess: () => setTouched(false) });
    };
    const act = (action: 'unpublish' | 'delete') =>
        router.post(`${base}/${document?.id ?? ''}/${action}`, { version: document?.version ?? 0 }, {
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setAsking(null);
            },
        });
    const state: DocumentState = { form, errors, checks, translation, set, cover, setCover: (image) => changed(() => setCover(image)), photos, setPhotos: (next) => changed(() => setPhotos(next)), products, setProducts: (next) => changed(() => setProducts(next)), uploadKind, storeId: store.id };

    return (
        <AdminLayout title={document === null ? words.new : document.ar.title} breadcrumbs={[{ label: words.list, href: `${kind === 'post' ? '/admin/content/blog' : base}?store=${encodeURIComponent(store.code)}` }]}>
            <form
                className="grid max-w-5xl min-w-0 gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    checks.submit(() => tick.ask((read) => send('publish', read), mainLabel));
                }}
                data-test="document-form"
            >
                <FormError />
                <div className="flex flex-wrap items-center gap-2 text-copy-14 text-ink-muted">
                    <span>{`${t('content::admin_shared.store')}: ${store.name}`}</span>
                    {document === null ? null : <StateBadge state={document.status} />}
                </div>
                <TranslateAllButton translation={translation} pairs={pairs} />
                <PairFields name="title" labelAr={t('content::admin_documents.fields.title_ar')} labelEn={t('content::admin_documents.fields.title_en')} helper={t('content::admin_documents.fields.title_rule')} ar={form.data.title_ar} en={form.data.title_en} onChange={set('title')} errors={errors} checks={checks} translation={translation} />
                <div className="grid gap-4 sm:grid-cols-2" data-test="slugs">
                    {(['ar', 'en'] as const).map((language) => (
                        <TextField
                            key={language}
                            id={`slug_${language}`}
                            dir={language === 'ar' ? 'rtl' : 'ltr'}
                            lang={language}
                            label={language === 'ar' ? t('content::admin_documents.fields.slug_ar') : t('content::admin_documents.fields.slug_en')}
                            helper={
                                <>
                                    <span className="block break-all" dir="ltr" data-test={`address-${language}`}>
                                        {t('content::admin_documents.fields.address', { address: address(language) })}
                                    </span>
                                    {document !== null && (language === 'ar' ? document.ar.slug : document.en?.slug ?? '') !== '' && form.data[`slug_${language}`] !== (language === 'ar' ? document.ar.slug : (document.en?.slug ?? '')) ? (
                                        <span className="block">{t('content::admin_documents.fields.slug_changed')}</span>
                                    ) : (
                                        <span className="block">{t('content::admin_documents.fields.slug_helper')}</span>
                                    )}
                                </>
                            }
                            error={errors[`slug_${language}`]}
                            value={form.data[`slug_${language}`]}
                            onChange={(event) => form.setData(`slug_${language}`, toLatinDigits(event.target.value))}
                            data-test={`slug_${language}`}
                        />
                    ))}
                </div>
                {(['ar', 'en'] as const).map((language) => (
                    <div key={language} className="grid min-w-0 gap-2">
                        <RichTextEditor
                            id={`body_${language}`}
                            label={language === 'ar' ? t('content::admin_documents.fields.body_ar') : t('content::admin_documents.fields.body_en')}
                            helper={language === 'en' && translation.marked.has('body') ? t('content::admin_shared.translate.marked') : t('content::admin_documents.fields.body_helper')}
                            language={language}
                            value={form.data[`body_${language}`]}
                            onChange={(value) => {
                                if (language === 'en') {
                                    translation.typed('body');
                                }

                                form.setData(`body_${language}`, value);
                            }}
                            images={images[language]}
                            onImage={(ref, image) => changed(() => setImages((was) => ({ ...was, [language]: { ...was[language], [ref]: image } })))}
                            uploadKind={uploadKind}
                            store={store.id}
                            // A shop path opens the store's page in that language; a full address as it is.
                            href={(link) => (link.startsWith('/') ? `${page.shopAddress}/${language}${link}` : link)}
                            check={checks.box(`body_${language}`, errors[`body_${language}`])}
                        />
                        {language === 'en' ? (
                            <div className="grid gap-2">
                                {translation.note('body') === undefined ? null : (
                                    <p className="text-copy-13 text-ink-muted" role="status" data-test="translate-note-body">
                                        {translation.note('body')}
                                    </p>
                                )}
                                <div>
                                    <TranslateButton translation={translation} pair={bodyPair} />
                                </div>
                            </div>
                        ) : null}
                    </div>
                ))}
                {children?.(state)}
                <Collapsible className="material-base grid min-w-0 gap-4 p-4" data-test="seo">
                    <CollapsibleTrigger className="group flex items-center justify-between gap-2 text-start text-heading-16 text-ink" data-test="seo-toggle">
                        {t('content::admin_documents.fields.seo')}
                        <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="grid min-w-0 gap-4">
                        <p className="text-copy-14 text-ink-muted">{t('content::admin_documents.fields.seo_helper')}</p>
                        <SeoPair name="seo_title" max={70} placeholder={{ ar: form.data.title_ar, en: form.data.title_en }} state={state} />
                        <SeoPair name="seo_description" max={160} rows={3} placeholder={{ ar: form.data.summary_ar, en: form.data.summary_en }} state={state} />
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(['ar', 'en'] as const).map((language) => (
                                <ImageBox
                                    key={language}
                                    id={`share_image_${language}`}
                                    label={language === 'ar' ? t('content::admin_documents.fields.share_ar') : t('content::admin_documents.fields.share_en')}
                                    helper={t('content::admin_documents.fields.share_helper')}
                                    kind={uploadKind}
                                    store={store.id}
                                    image={share[language]}
                                    onChange={(image) => changed(() => setShare((was) => ({ ...was, [language]: image })))}
                                    size={{ width: 1200, height: 630 }}
                                    error={errors[`share_image_${language}`]}
                                />
                            ))}
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                <div className="flex flex-wrap items-center gap-3">
                    <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="publish">
                        {mainLabel}
                    </ActionButton>
                    {published ? null : (
                        <ActionButton type="button" variant="outline" loading={form.processing} onClick={() => checks.submit(() => send('draft'))} data-test="save-draft">
                            {t('content::admin_documents.save_draft')}
                        </ActionButton>
                    )}
                    {document === null ? null : (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <MoreButton ref={more} name={document.ar.title} data-test="document-more" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="min-w-48">
                                {published ? (
                                    <DropdownMenuItem variant="destructive" onSelect={() => setAsking('unpublish')} data-test="menu-unpublish">{`${words.unpublishMenu}…`}</DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem variant="destructive" onSelect={() => setAsking('delete')} data-test="menu-delete">{`${words.deleteMenu}…`}</DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </form>
            {asking === null ? null : (
                <Suspense fallback={null}>
                    <ScheduleConfirm
                        returnFocusTo={more}
                        title={asking === 'unpublish' ? words.unpublishTitle : words.deleteTitle}
                        description={`${asking === 'unpublish' ? words.unpublishBody : words.deleteBody}${linksNote}`}
                        label={asking === 'unpublish' ? words.unpublishConfirm : words.deleteConfirm}
                        busy={busy}
                        onConfirm={() => act(asking)}
                        onClose={() => setAsking(null)}
                        test={`confirm-${asking}`}
                    />
                </Suspense>
            )}
            {translation.dialog}
            {tick.dialog}
            {leave}
        </AdminLayout>
    );
}

/** A search box per language with its count of characters, the default it falls back to as its placeholder. */
function SeoPair({ name, max, rows, placeholder, state }: { name: 'seo_title' | 'seo_description'; max: number; rows?: number; placeholder: Words; state: DocumentState }) {
    const t = useTranslator();
    const { form, errors, checks, translation } = state;
    const labels = name === 'seo_title' ? { ar: t('content::admin_documents.fields.seo_title_ar'), en: t('content::admin_documents.fields.seo_title_en') } : { ar: t('content::admin_documents.fields.seo_description_ar'), en: t('content::admin_documents.fields.seo_description_en') };
    const count = (language: Lang) => t('content::admin_documents.fields.count', { count: [...form.data[`${name}_${language}`]].length, max });
    const helpers = { ar: count('ar'), en: count('en') };

    return (
        <PairFields
            name={name}
            rows={rows}
            labelAr={labels.ar}
            labelEn={labels.en}
            helpers={helpers}
            ar={form.data[`${name}_ar`]}
            en={form.data[`${name}_en`]}
            onChange={(language, value) => form.setData(`${name}_${language}`, value)}
            errors={errors}
            checks={checks}
            translation={translation}
            placeholders={placeholder}
        />
    );
}

/** A link a body names, checked as the server will (§1.1 rule 16): for the page's own checks. */
export const bodyLinksFine = (body: string): boolean => [...body.matchAll(/\]\(([^\s)]*)\)/g)].every((found) => (found[1] ?? '').startsWith('image:') || isShopLink(found[1] ?? ''));
