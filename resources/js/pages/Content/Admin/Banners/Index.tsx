import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ImageIcon } from 'lucide-react';
import { PhoneCards, StateBadge } from '@/components/content/list';
import { ScheduleMenu, type ScheduleWords } from '@/components/content/schedule-menu';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import type { BannerRowData, BannersPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { DragHandle, SortableGroup, SortableGroups, useSaveQueue, useSortableRow } from '../../../Catalog/Admin/SortableList';

/*
| W3 · Banners (content.md §4.4, §1.6): tabs by placement, each with its count; a placement's banners in their order,
| dragged by the handle or moved to the top or the bottom from a row's menu (the whole order is sent each time); the
| whole row opens the banner's own page. "Live at once" is met by the order: the note says how many show.
*/

const LIST = '/admin/content/banners';
const PLACEMENTS = ['HOME_HERO', 'HOME_PHONE', 'LISTING'] as const;

export default function Index({ stores, storeCode, placement, counts, banners, categories }: BannersPageData) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const store = stores.find((each) => each.code === storeCode);
    // The order last made shows until every save is answered, as Catalog's useSavedOrder does - which sends one id for
    // Move to Top, where Content's orders are always sent whole, with the store and the placement (Ordering).
    const [sent, setSent] = useState<string[] | null>(null);
    const queue = useSaveQueue(() => setSent(null));
    const order = sent ?? banners.map((banner) => banner.id);
    const save = (ids: string[]) => {
        setSent(ids);
        queue((answered) => router.post(`${LIST}/order`, { store: store?.id ?? '', placement, ids }, { async: true, preserveScroll: true, onFinish: answered }));
    };
    const byId = new Map(banners.map((banner) => [banner.id, banner]));
    const rows = order.flatMap((id) => byId.get(id) ?? []);
    const names: Record<string, string> = {
        HOME_HERO: t('content::admin_banners.placement.HOME_HERO'),
        HOME_PHONE: t('content::admin_banners.placement.HOME_PHONE'),
        LISTING: t('content::admin_banners.placement.LISTING'),
    };
    const limits: Record<string, string> = {
        HOME_HERO: t('content::admin_banners.limit.HOME_HERO'),
        HOME_PHONE: t('content::admin_banners.limit.HOME_PHONE'),
        LISTING: t('content::admin_banners.limit.LISTING'),
    };
    const words: ScheduleWords = {
        edit: t('content::admin_banners.menu.edit'),
        end: t('content::admin_banners.menu.end'),
        draft: t('content::admin_banners.menu.draft'),
        delete: t('content::admin_banners.menu.delete'),
        endTitle: t('content::admin_banners.end.title'),
        endBody: t('content::admin_banners.end.body'),
        endConfirm: t('content::admin_banners.end.confirm'),
        deleteTitle: t('content::admin_banners.delete.title'),
        deleteBody: t('content::admin_banners.delete.body'),
        deleteConfirm: t('content::admin_banners.delete.confirm'),
    };
    const addBanner = `${LIST}/new?store=${encodeURIComponent(storeCode)}&placement=${placement}`;
    const shownOn = (row: BannerRowData) => {
        if (placement !== 'LISTING') {
            return t('content::admin_banners.home');
        }

        const category = categories.find((each) => each.id === row.categoryId);

        return category === undefined ? t('content::admin_banners.all_lists') : inLanguage(category.pathAr, category.pathEn);
    };
    const menu = (row: BannerRowData, index: number) => (
        <ScheduleMenu
            id={row.id}
            name={row.name}
            state={row.state}
            base={LIST}
            words={words}
            extra={
                <>
                    {index > 0 ? (
                        <DropdownMenuItem onSelect={() => save([row.id, ...order.filter((id) => id !== row.id)])} data-test="move-top">
                            {t('content::admin_shared.order.top')}
                        </DropdownMenuItem>
                    ) : null}
                    {index < rows.length - 1 ? (
                        <DropdownMenuItem onSelect={() => save([...order.filter((id) => id !== row.id), row.id])} data-test="move-bottom">
                            {t('content::admin_shared.order.bottom')}
                        </DropdownMenuItem>
                    ) : null}
                </>
            }
        />
    );

    return (
        <AdminLayout
            title={t('content::admin_banners.title')}
            subtitle={t('content::admin_banners.subtitle')}
            action={
                <Button asChild>
                    <Link href={addBanner} data-test="add-banner">
                        {t('content::admin_banners.add')}
                    </Link>
                </Button>
            }
        >
            <div className="grid min-w-0 gap-4">
                <FormError />
                <StoreFilter stores={stores.map((each) => ({ code: each.code, name: each.name, isActive: each.isOn }))} value={storeCode} className="max-w-xs" />
                <Tabs value={placement} onValueChange={(next) => router.get(LIST, { store: storeCode, placement: next }, { preserveScroll: true })}>
                    <div className="max-w-full overflow-x-auto">
                        <TabsList variant="line">
                            {PLACEMENTS.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`tab-${each}`}>
                                    {names[each]}
                                    <span className="tw-figure text-ink-muted">{counts[each] ?? 0}</span>
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </div>
                </Tabs>
                <Note size="small" data-test="limit-note">
                    {limits[placement]}
                </Note>

                {rows.length === 0 ? (
                    <Empty className="material-base" data-test="banners-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_banners.empty')}</EmptyTitle>
                            <EmptyDescription>{t('content::admin_banners.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={addBanner}>{t('content::admin_banners.add')}</Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <SortableGroups groups={{ banners: order }} labelOf={(id) => byId.get(id)?.name ?? ''} onMove={(_, ids) => save(ids)}>
                            <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                                <Table>
                                    <TableCaption className="sr-only">{names[placement]}</TableCaption>
                                    <TableHeader className="bg-surface-sunken">
                                        <TableRow>
                                            <TableHead className="w-10">
                                                <span className="sr-only">{t('content::admin_banners.columns.order')}</span>
                                            </TableHead>
                                            <TableHead className="w-16">
                                                <span className="sr-only">{t('content::admin_banners.columns.image')}</span>
                                            </TableHead>
                                            <TableHead>{t('content::admin_banners.columns.name')}</TableHead>
                                            <TableHead>{t('content::admin_banners.columns.shown_on')}</TableHead>
                                            <TableHead>{t('content::admin_banners.columns.state')}</TableHead>
                                            <TableHead>{t('content::admin_banners.columns.starts')}</TableHead>
                                            <TableHead>{t('content::admin_banners.columns.ends')}</TableHead>
                                            <TableHead className="w-10">
                                                <span className="sr-only">{t('content::admin_shared.actions')}</span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <SortableGroup id="banners" ids={order}>
                                            {rows.map((row, index) => (
                                                <BannerRow key={row.id} row={row} shownOn={shownOn(row)} menu={menu(row, index)} />
                                            ))}
                                        </SortableGroup>
                                    </TableBody>
                                </Table>
                            </div>
                        </SortableGroups>
                        <PhoneCards
                            test="banner"
                            cards={rows.map((row, index) => ({
                                id: row.id,
                                title: row.name,
                                badge: <StateBadge state={row.state} />,
                                lines: [shownOn(row)],
                                menu: menu(row, index),
                                onOpen: () => router.visit(`${LIST}/${row.id}`),
                            }))}
                        />
                    </>
                )}
            </div>
        </AdminLayout>
    );
}

function BannerRow({ row, shownOn, menu }: { row: BannerRowData; shownOn: string; menu: ReactNode }) {
    const t = useTranslator();
    const sortable = useSortableRow(row.id);

    return (
        <TableRow
            ref={sortable.ref}
            style={sortable.style}
            data-dragging={sortable.dragging || undefined}
            className="relative z-0 cursor-pointer data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            onClick={() => router.visit(`${LIST}/${row.id}`)}
            data-test={`banner-${row.id}`}
        >
            <TableCell onClick={(event) => event.stopPropagation()}>
                <DragHandle handle={sortable.handle} label={row.name} test={`drag-${row.id}`} />
            </TableCell>
            <TableCell>
                <div className="material-small flex h-10 w-14 items-center justify-center overflow-hidden bg-surface-sunken">
                    {row.thumbnail === null ? <ImageIcon className="size-4 text-ink-muted" aria-hidden /> : <img src={row.thumbnail} alt="" className="size-full object-cover" />}
                </div>
            </TableCell>
            <TableCell className="max-w-64 truncate text-label-14 text-ink">
                <Link href={`${LIST}/${row.id}`} onClick={(event) => event.stopPropagation()} className="hover:underline">
                    {row.name}
                </Link>
            </TableCell>
            <TableCell className="max-w-56 truncate text-ink-muted">{shownOn}</TableCell>
            <TableCell>
                <StateBadge state={row.state} />
            </TableCell>
            <TableCell className="whitespace-nowrap text-ink-muted">{row.startsAt === null ? t('content::admin_banners.now') : <Time value={row.startsAt} mode="absolute" focusable={false} />}</TableCell>
            <TableCell className="whitespace-nowrap text-ink-muted">{row.endsAt === null ? t('content::admin_banners.no_end') : <Time value={row.endsAt} mode="absolute" focusable={false} />}</TableCell>
            <TableCell className="text-end" onClick={(event) => event.stopPropagation()}>
                {menu}
            </TableCell>
        </TableRow>
    );
}
