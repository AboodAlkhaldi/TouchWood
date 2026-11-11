import { Link, router, usePage } from '@inertiajs/react';
import { PhoneCards, StateBadge } from '@/components/content/list';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/AdminLayout';
import { figure } from '@/lib/digits';
import { useInLanguage } from '@/lib/in-language';
import { useKeptDepth } from '@/lib/kept-depth';
import { zoneName } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ThemeRowData, ThemesPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| W5 · Themes (content.md §4.4), a Super Admin's: every theme, newest first, 50 at a time with Show More. Each row names
| its stores with each one's state, its dates as typed - each store reads them on its own clock, so the first store's
| zone is named (§1.1 rule 6) - how many of its 11 parts are filled, and its state: one when its stores agree, Mixed
| otherwise (§4.1). The whole row opens the theme's page.
*/

const LIST = '/admin/content/themes';

export default function Index({ themes, next }: ThemesPageData) {
    const t = useTranslator();
    const name = useInLanguage();
    const { locale } = usePage<SharedProps>().props;
    const list = useLoadMore<ThemeRowData>(themes, (theme) => theme.id);
    const more = (cursor: string) => list.more(LIST, { after: cursor }, ['themes', 'next']);
    const restoring = useKeptDepth(list, next, more);
    const newTheme = `${LIST}/new`;
    const title = (row: ThemeRowData) => name(row.nameAr, row.nameEn ?? row.nameAr);
    const moment = (row: ThemeRowData, local: string) => (
        <span className="tw-figure whitespace-nowrap">
            <bdi dir="ltr">{local}</bdi>
            {row.zone === null ? null : <span className="block text-copy-13 text-ink-muted">{zoneName(row.zone, locale)}</span>}
        </span>
    );
    const stores = (row: ThemeRowData) => (
        <span className="flex flex-wrap gap-1" data-test="theme-stores">
            {row.stores.map((store) => (
                <span key={store.storeId} className="inline-flex items-center gap-1 text-copy-13 text-ink-muted">
                    <bdi dir="ltr" className="font-mono uppercase">
                        {store.code}
                    </bdi>
                    <StateBadge state={store.state} />
                </span>
            ))}
        </span>
    );
    const parts = (row: ThemeRowData) => <span className="tw-figure">{t('content::admin_themes.parts_count', { count: figure(locale, row.parts) })}</span>;

    return (
        <AdminLayout
            title={t('content::admin_themes.title')}
            subtitle={t('content::admin_themes.subtitle')}
            action={
                <Button asChild>
                    <Link href={newTheme} data-test="new-theme">
                        {t('content::admin_themes.new')}
                    </Link>
                </Button>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="themes-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_themes.empty')}</EmptyTitle>
                            <EmptyDescription>{t('content::admin_themes.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={newTheme}>{t('content::admin_themes.new')}</Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{t('content::admin_themes.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('content::admin_themes.columns.name')}</TableHead>
                                        <TableHead>{t('content::admin_themes.columns.stores')}</TableHead>
                                        <TableHead>{t('content::admin_themes.columns.starts')}</TableHead>
                                        <TableHead>{t('content::admin_themes.columns.ends')}</TableHead>
                                        <TableHead>{t('content::admin_themes.columns.parts')}</TableHead>
                                        <TableHead>{t('content::admin_themes.columns.state')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <TableRow key={row.id} className="cursor-pointer" onClick={() => router.visit(`${LIST}/${row.id}`)} data-test={`theme-${row.id}`}>
                                            <TableCell className="max-w-72 truncate text-label-14 text-ink">
                                                <Link href={`${LIST}/${row.id}`} onClick={(event) => event.stopPropagation()} className="hover:underline">
                                                    {title(row)}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{stores(row)}</TableCell>
                                            <TableCell className="text-ink-muted">{moment(row, row.startsLocal)}</TableCell>
                                            <TableCell className="text-ink-muted">{moment(row, row.endsLocal)}</TableCell>
                                            <TableCell className="text-ink-muted">{parts(row)}</TableCell>
                                            <TableCell>
                                                <StateBadge state={row.state} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <PhoneCards
                            test="theme"
                            cards={list.rows.map((row) => ({
                                id: row.id,
                                title: title(row),
                                badge: <StateBadge state={row.state} />,
                                lines: [
                                    <bdi key="dates" dir="ltr" className="tw-figure">{`${row.startsLocal} – ${row.endsLocal}`}</bdi>,
                                    <span key="parts">{parts(row)}</span>,
                                    <span key="stores">{stores(row)}</span>,
                                ],
                                href: `${LIST}/${row.id}`,
                            }))}
                        />
                        {next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(next)} /> : null}
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
