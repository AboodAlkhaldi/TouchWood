import { useState } from 'react';
import { X } from 'lucide-react';
import { DocumentForm } from '@/components/content/document-form';
import { ImageBox } from '@/components/content/image-box';
import { PairFields } from '@/components/content/parts';
import { ProductsField } from '@/components/content/products-field';
import { SortableList } from '@/components/content/sortable';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';
import type { ContentImageData, DocumentFormPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W9 · New Project and a project's own page (content.md §4.4, §1.13): as a page, plus its city per language, its photos -
| 1 to 20, a grid dragged into order, each uploaded at once - and up to 20 products used.
*/

export default function Form(page: DocumentFormPageData) {
    const t = useTranslator();

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
            extraChecks={(data) => [
                { id: 'city_ar', label: t('content::admin_projects.fields.city_ar'), value: data.city_ar, rules: { length: { max: 100 } } },
                { id: 'city_en', label: t('content::admin_projects.fields.city_en'), value: data.city_en, rules: { length: { max: 100 } } },
            ]}
        >
            {({ form, errors, checks, translation, set, photos, setPhotos, products, setProducts, uploadKind, storeId }) => (
                <>
                    <PairFields name="city" labelAr={t('content::admin_projects.fields.city_ar')} labelEn={t('content::admin_projects.fields.city_en')} helper={t('content::admin_projects.fields.city_rule')} ar={form.data.city_ar} en={form.data.city_en} onChange={set('city')} errors={errors} checks={checks} translation={translation} />
                    <Photos photos={photos} onChange={setPhotos} kind={uploadKind} store={storeId} error={errors.photos} />
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
