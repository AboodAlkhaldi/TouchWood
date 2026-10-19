import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Unknown, useLocale } from '@/components/promotions/parts';
import { useRuleWords } from '@/components/promotions/segments';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import { SegmentDialog } from '@/pages/Promotions/Admin/Segments/SegmentDialog';
import type { SegmentsListPage } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M3 - Marketing › Segments (promotions.md §4.1): the store's segments, newest first, 50 at a time with
| Show More; New Segment… for a manager (Q16).
*/

const LIST = '/admin/segments';

export default function Index({ segments, nextAt, nextId, mayManage, storeCode, stores }: SegmentsListPage) {
    const t = useTranslator();
    const locale = useLocale();
    const rule = useRuleWords();
    const list = useLoadMore(segments, (row) => row.id);
    const [creating, setCreating] = useState(false);
    const newButton = (test: string) =>
        mayManage ? (
            <Button type="button" onClick={() => setCreating(true)} data-test={test}>
                {`${t('promotions::admin_segments.list.new')}…`}
            </Button>
        ) : null;

    return (
        <AdminLayout title={t('promotions::admin_segments.list.title')} subtitle={t('promotions::admin_segments.list.subtitle')} action={newButton(`new-segment`) ?? undefined}>
            <div className="grid gap-4">
                <FormError />
                <div className="grid sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]">
                    <div className="sm:col-start-2">
                        <StoreFilter stores={stores} value={storeCode} />
                    </div>
                </div>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="segments-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('promotions::admin_segments.list.empty_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::admin_segments.list.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        {mayManage ? <EmptyContent>{newButton(`empty-new-segment`)}</EmptyContent> : null}
                    </Empty>
                ) : (
                    <div className="material-base max-w-full overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('promotions::admin_segments.list.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('promotions::admin_segments.columns.name')}</TableHead>
                                    <TableHead>{t('promotions::admin_segments.columns.type')}</TableHead>
                                    <TableHead>{t('promotions::admin_segments.columns.who')}</TableHead>
                                    <TableHead>{t('promotions::admin_segments.columns.rule')}</TableHead>
                                    <TableHead className="text-end">{t('promotions::admin_segments.columns.members')}</TableHead>
                                    <TableHead>{t('promotions::admin_segments.columns.updated')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((segment) => (
                                    <TableRow key={segment.id} data-test={`segment-${segment.id}`} className="relative hover:bg-surface-sunken">
                                        <TableCell className="min-w-40">
                                            <Link
                                                href={`${LIST}/${segment.id}`}
                                                className="text-label-14 text-ink after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-[3px] focus-visible:after:ring-ring/50"
                                            >
                                                {segment.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-ink-muted">{t(`promotions::admin_segments.type.${segment.type}`)}</TableCell>
                                        <TableCell className="text-ink-muted">{t(`promotions::admin_segments.audience.${segment.audience}`)}</TableCell>
                                        <TableCell>{rule(segment) ?? <Unknown />}</TableCell>
                                        <TableCell className="text-end">
                                            <bdi dir="ltr" className="tw-figure">
                                                {figure(locale, segment.memberCount)}
                                            </bdi>
                                        </TableCell>
                                        <TableCell className="text-copy-13 whitespace-nowrap text-ink-muted">
                                            {segment.refreshedAt === null ? (
                                                <Unknown />
                                            ) : (
                                                <span className="relative z-10">
                                                    <Time value={segment.refreshedAt} />
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {nextAt !== null && nextId !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, { store: storeCode, before_at: nextAt, before_id: nextId }, ['segments', 'nextAt', 'nextId'])} /> : null}
            </div>

            {mayManage ? <SegmentDialog open={creating} onOpenChange={setCreating} storeCode={storeCode} /> : null}
        </AdminLayout>
    );
}
