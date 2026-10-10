import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { useTranslator } from '@/lib/t';
import type { ProductPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { PhotoGrid } from './photos';
import { ProductShell } from './shell';

/*
| A product's gallery (catalog.md §4.4 S9): at most 20 photos, the first the card's photo, ordered by
| dragging; a product is shown in the shop only with a photo whose sizes are ready.
*/

export function PhotosTab({ page }: { page: ProductPage }) {
    const t = useTranslator();

    return (
        <Card className="material-base border-0">
            <CardContent className="grid gap-4 pt-5">
                {page.gallery.length === 0 ? (
                    <Empty className="border-0 p-0" data-test="photos-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_products.photos.empty_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_products.photos.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : null}
                <PhotoGrid
                    photos={page.gallery}
                    url={`/admin/products/${page.product.id}/gallery`}
                    max={20}
                    helper={t('catalog::admin_products.photos.helper')}
                    first={t('catalog::admin_products.photos.card')}
                    reason={page.mayUpdate ? undefined : t('catalog::admin_products.read_only')}
                    testPrefix="gallery"
                />
            </CardContent>
        </Card>
    );
}

/** The Photos tab's page: this product above its tabs, this one open (ProductShell). */
export default function PhotosTabPage(page: ProductPage) {
    return (
        <ProductShell page={page}>
            <PhotosTab page={page} />
        </ProductShell>
    );
}
