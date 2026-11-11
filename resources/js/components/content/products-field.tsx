import { lazy, Suspense, useState } from 'react';
import { Plus, X } from 'lucide-react';
import { SortableList } from '@/components/content/sortable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import type { ProductChoiceData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| A post's or a project's linked products (content.md §1.12, §1.13): ready products of the store, found by a name or a
| code in a dialog loaded when Add Product is pressed, each once, at most 8 or 20, dragged into order. One archived since
| it was linked stays listed, marked Archived (§1.1 rule 17).
*/

const ProductDialog = lazy(() => import('./products-dialog').then((module) => ({ default: module.ProductDialog })));

export function ProductsField({ products, onChange, max, search, store, error }: { products: ProductChoiceData[]; onChange: (products: ProductChoiceData[]) => void; max: number; search: string; store: string; error?: string }) {
    const t = useTranslator();
    const name = useInLanguage();
    const [adding, setAdding] = useState(false);
    const label = (product: ProductChoiceData) => name(product.nameAr, product.nameEn ?? product.nameAr);

    return (
        <FieldSet className="min-w-0" data-test="products">
            <FieldLegend variant="label">{t('content::admin_documents.fields.products')}</FieldLegend>
            <FieldDescription>{t('content::admin_documents.fields.products_helper', { max })}</FieldDescription>
            {products.length === 0 ? (
                <p className="text-copy-14 text-ink-muted">{t('content::admin_documents.fields.none_linked')}</p>
            ) : (
                <SortableList
                    testPrefix="product"
                    items={products.map((product) => ({
                        id: product.id,
                        label: label(product),
                        content: (
                            <span className="flex min-w-0 items-center gap-2">
                                <span className="truncate text-copy-14 text-ink">{label(product)}</span>
                                {product.archived ? <Badge variant="secondary">{t('content::admin_documents.fields.product_archived')}</Badge> : null}
                            </span>
                        ),
                        extra: (
                            <Button type="button" variant="ghost" size="icon-sm" aria-label={t('content::admin_documents.fields.remove_named', { name: label(product) })} onClick={() => onChange(products.filter((each) => each.id !== product.id))} data-test={`remove-product-${product.id}`}>
                                <X aria-hidden="true" />
                            </Button>
                        ),
                    }))}
                    onChange={(ids) => onChange(ids.flatMap((id) => products.filter((product) => product.id === id)))}
                />
            )}
            {error ? <FieldError>{error}</FieldError> : null}
            <div>
                <Button type="button" variant="outline" onClick={() => setAdding(true)} data-test="add-product">
                    <Plus aria-hidden="true" />
                    {t('content::admin_documents.fields.add_product')}
                </Button>
            </div>
            {adding ? (
                <Suspense fallback={null}>
                    <ProductDialog search={search} store={store} chosen={products} max={max} onAdd={(product) => onChange([...products, product])} onClose={() => setAdding(false)} />
                </Suspense>
            ) : null}
        </FieldSet>
    );
}
