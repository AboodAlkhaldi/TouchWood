import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
import { ProductCell } from '@/components/feedback/parts';
import { useLocale } from '@/components/promotions/parts';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { SupportListPage } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| F3 - Customers › Favourited Products (feedback.md §4.1): each product customers saved in this store
| with how many saved it, most saved first (owner's answer 8), 50 at a time with Show More - a page may
| come back short with more after it (§3).
*/

const W = 'feedback::admin_support';
const LIST = '/admin/favourites';

export default function Favourites(page: SupportListPage) {
    const t = useTranslator();
    const locale = useLocale();
    const list = useLoadMore(page.favourites, (row) => row.product.id);

    return (
        <AdminLayout title={t(`${W}.favourites.title`)} subtitle={t(`${W}.favourites.subtitle`)}>
            <div className="grid gap-4">
                <FormError />
                <div className="grid sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]">
                    <div className="sm:col-start-2">
                        <StoreFilter stores={page.stores} value={page.storeCode} />
                    </div>
                </div>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="favourites-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${W}.favourites.empty`)}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="material-base max-w-full overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t(`${W}.favourites.title`)}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t(`${W}.favourites.product`)}</TableHead>
                                    <TableHead className="text-end">{t(`${W}.favourites.saved_by`)}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((row) => (
                                    <TableRow key={row.product.id} data-test={`favourite-${row.product.id}`}>
                                        <TableCell>
                                            <ProductCell product={row.product} />
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <bdi dir="ltr" className="tw-figure">
                                                {figure(locale, row.savedBy)}
                                            </bdi>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {page.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, { store: page.storeCode, after: page.next ?? '' }, ['favourites', 'next'])} /> : null}
            </div>
        </AdminLayout>
    );
}
