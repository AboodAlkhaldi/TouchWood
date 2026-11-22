import { type ComponentProps, type ComponentType, lazy, Suspense, useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { DocumentForm } from '@/components/content/document-form';
import { ImageBox } from '@/components/content/image-box';
import { httpsLinkRule, PairFields } from '@/components/content/parts';
import { ProductsField } from '@/components/content/products-field';
import { SortableList } from '@/components/content/sortable';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/Fields';
import type { VideosField as Field } from '@/components/VideosField';
import { FieldDescription, FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';
import type { ContentImageData, DocumentFormPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W9 · New Project and a project's own page (content.md §4.4, §1.13): as a page, plus its city per language, its client
| (a name per language, a logo from the library's upload box, an https link; all optional, flags-2026-11.md S4.1), its
| photos - 1 to 20, a grid dragged into order, each uploaded at once - its YouTube videos (up to 10, pasted, dragged,
| removed, a title per language: flags-2026-11.md S4.4) and up to 20 products used.
*/

// The videos' part is fetched once the page is drawn (the form's 60 KB, JavaScriptBudgetTest): first paint shows its legend.
// One that cannot be fetched leaves the legend alone, the videos kept as they are when the form is saved (the review of 5b).
const VideosField = lazy<ComponentType<ComponentProps<typeof Field>>>(() =>
    import('@/components/VideosField')
        .then((module) => ({ default: module.VideosField }))
        .catch(() => ({ default: () => null })),
);
const videoTitleBox = (language: 'ar' | 'en', videoId: string) => `video_title_${language}_${videoId}`;

export default function Form(page: DocumentFormPageData) {
    const t = useTranslator();
    const [drawn, setDrawn] = useState(false);
    const waiting = (
        <FieldSet className="min-w-0">
            <FieldLegend variant="label">{t('content::admin_projects.fields.videos')}</FieldLegend>
        </FieldSet>
    );

    useEffect(() => setDrawn(true), []);

    return (
        <DocumentForm
            page={page}
            words={{
                list: t('content::admin_projects.title'),
                new: t('content::admin_projects.new'),
                item: t('content::admin_projects.item'),
                publish: t('content::admin_projects.publish'),
                unpublishMenu: t('content::admin_projects.unpublish.menu'),
                unpublishTitle: t('content::admin_projects.unpublish.title'),
                unpublishBody: t('content::admin_projects.unpublish.body'),
                unpublishConfirm: t('content::admin_projects.unpublish.confirm'),
                deleteMenu: t('content::admin_projects.delete.menu'),
                deleteTitle: t('content::admin_projects.delete.title'),
                deleteBody: t('content::admin_projects.delete.body'),
                deleteConfirm: t('content::admin_projects.delete.confirm'),
            }}
            extraChecks={(data, published) => [
                { id: 'city_ar', label: t('content::admin_projects.fields.city_ar'), value: data.city_ar, rules: { length: { max: 100 } } },
                { id: 'city_en', label: t('content::admin_projects.fields.city_en'), value: data.city_en, rules: { length: { max: 100 } } },
                { id: 'client_name_ar', label: t('content::admin_projects.fields.client_name_ar'), value: data.client_name_ar, rules: { length: { max: 100 } } },
                { id: 'client_name_en', label: t('content::admin_projects.fields.client_name_en'), value: data.client_name_en, rules: { length: { max: 100 } } },
                { id: 'client_link', label: t('content::admin_projects.fields.client_link'), value: data.client_link, rules: { own: httpsLinkRule } },
                // Each video's titles as every pair (Words::pair): Arabic before English, English to publish, 100 characters.
                ...data.videos.flatMap((video) => [
                    { id: videoTitleBox('ar', video.videoId), label: t('content::admin_projects.fields.video_title_ar'), value: video.titleAr, rules: { required: video.titleEn.trim() !== '', length: { max: 100 } } },
                    { id: videoTitleBox('en', video.videoId), label: t('content::admin_projects.fields.video_title_en'), value: video.titleEn, rules: { required: published && video.titleAr.trim() !== '', length: { max: 100 } } },
                ]),
            ]}
        >
            {({ form, errors, checks, translation, set, clientLogo, setClientLogo, photos, setPhotos, products, setProducts, uploadKind, storeId }) => (
                <>
                    <PairFields name="city" labelAr={t('content::admin_projects.fields.city_ar')} labelEn={t('content::admin_projects.fields.city_en')} helper={t('content::admin_projects.fields.city_rule')} ar={form.data.city_ar} en={form.data.city_en} onChange={set('city')} errors={errors} checks={checks} translation={translation} />
                    <PairFields name="client_name" labelAr={t('content::admin_projects.fields.client_name_ar')} labelEn={t('content::admin_projects.fields.client_name_en')} helper={t('content::admin_projects.fields.client_name_rule')} ar={form.data.client_name_ar} en={form.data.client_name_en} onChange={set('client_name')} errors={errors} checks={checks} translation={translation} />
                    <ImageBox id="client_logo" label={t('content::admin_projects.fields.client_logo')} helper={t('content::admin_projects.fields.client_logo_helper')} kind={uploadKind} store={storeId} image={clientLogo} onChange={setClientLogo} size={{ width: 360, height: 156, png: true }} error={errors.client_logo} />
                    <TextField id="client_link" type="url" dir="ltr" label={t('content::admin_projects.fields.client_link')} helper={t('content::admin_projects.fields.client_link_helper')} value={form.data.client_link} onChange={(event) => form.setData('client_link', event.target.value)} error={errors.client_link} check={checks.box('client_link', errors.client_link)} data-test="client_link" />
                    <Photos photos={photos} onChange={setPhotos} kind={uploadKind} store={storeId} error={errors.photos} />
                    {drawn ? (
                        <Suspense fallback={waiting}>
                            <VideosField
                                videos={form.data.videos}
                                onChange={(next) => form.setData('videos', next)}
                                titleBox={videoTitleBox}
                                words={{
                                    legend: t('content::admin_projects.fields.videos'),
                                    helper: t('content::admin_projects.fields.videos_helper'),
                                    link: t('content::admin_projects.fields.video_link'),
                                    linkHelper: t('content::admin_projects.fields.video_link_helper'),
                                    add: t('content::admin_projects.fields.add_video'),
                                    titleAr: t('content::admin_projects.fields.video_title_ar'),
                                    titleEn: t('content::admin_projects.fields.video_title_en'),
                                    none: t('content::admin_projects.fields.no_videos'),
                                    max: t('content::admin_projects.fields.videos_full'),
                                    pasteKey: 'content::admin_projects.fields.video_paste',
                                    onceKey: 'content::admin_projects.fields.video_once',
                                    video: (number) => t('content::admin_projects.fields.video', { number }),
                                    removeNamed: (name) => t('content::admin_documents.fields.remove_named', { name }),
                                }}
                                checks={checks}
                                errors={errors}
                                max={10}
                            />
                        </Suspense>
                    ) : (
                        waiting
                    )}
                    <ProductsField products={products} onChange={setProducts} max={20} search="/admin/content/projects/products" store={storeId} error={errors.products} />
                </>
            )}
        </DocumentForm>
    );
}

/** The project's photos: a grid dragged into order, a new one uploaded into the box under it, each removable. */
function Photos({ photos, onChange, kind, store, error }: { photos: ContentImageData[]; onChange: (photos: ContentImageData[]) => void; kind: string; store: string; error?: string }) {
    const t = useTranslator();
    // A fresh box after each upload: it holds only the file being added.
    const [round, setRound] = useState(0);

    return (
        <FieldSet className="min-w-0" data-test="photos">
            <FieldLegend variant="label">{t('content::admin_projects.fields.photos')}</FieldLegend>
            <FieldDescription>{t('content::admin_projects.fields.photos_helper')}</FieldDescription>
            {photos.length === 0 ? (
                <p className="text-copy-14 text-ink-muted">{t('content::admin_projects.fields.no_photos')}</p>
            ) : (
                <SortableList
                    layout="grid"
                    testPrefix="photo"
                    items={photos.map((photo, index) => ({
                        id: photo.id,
                        label: t('content::admin_projects.fields.photo', { number: index + 1 }),
                        content: photo.thumbnail ? <img src={photo.thumbnail} alt="" className="aspect-square w-full rounded-md object-cover" /> : <span className="text-copy-13 break-all text-ink-muted">{photo.name}</span>,
                        extra: (
                            <Button type="button" variant="ghost" size="icon-sm" aria-label={t('content::admin_documents.fields.remove_named', { name: t('content::admin_projects.fields.photo', { number: index + 1 }) })} onClick={() => onChange(photos.filter((each) => each.id !== photo.id))} data-test={`remove-photo-${index}`}>
                                <X aria-hidden="true" />
                            </Button>
                        ),
                    }))}
                    onChange={(ids) => onChange(ids.flatMap((id) => photos.filter((photo) => photo.id === id)))}
                />
            )}
            {error ? <FieldError>{error}</FieldError> : null}
            {photos.length >= 20 ? (
                <p className="text-copy-14 text-ink-muted">{t('content::admin_projects.fields.photos_full')}</p>
            ) : (
                <ImageBox
                    key={round}
                    id="add-photo"
                    label={t('content::admin_projects.fields.add_photo')}
                    kind={kind}
                    store={store}
                    image={null}
                    onChange={(image) => {
                        if (image !== null && !photos.some((photo) => photo.id === image.id)) {
                            onChange([...photos, image]);
                        }

                        setRound((was) => was + 1);
                    }}
                />
            )}
        </FieldSet>
    );
}
