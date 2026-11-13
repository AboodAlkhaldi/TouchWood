import { Link, router, usePage } from '@inertiajs/react';
import { StateBadge } from '@/components/content/list';
import { PhoneCards } from '@/components/PhoneCards';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
import { TabCount } from '@/components/TabCount';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { figure } from '@/lib/digits';
import { useInLanguage } from '@/lib/in-language';
import { useKeptDepth } from '@/lib/kept-depth';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { CampaignRowData, CampaignsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| W4 · Campaigns (content.md §4.4): the store's campaigns by its run's state - All, Scheduled, Live, Ended (a cancelled run
| with the ended), each with its count - newest first, 50 at a time with Show More. Each row names every store with its
| state (§4.1: a campaign's badge is one state when its stores agree, Mixed otherwise), the theme, the notice. The whole row
| opens the campaign's page, where everything it does is.
|
| The Offers column: per store how many sizes its run prices now ("SA 412 · AE 380"), read from Pricing for every row at
| once (`CampaignPrices::reachedSizes`); a draft's, which Pricing holds nothing of yet, a dash.
*/

const LIST = '/admin/content/campaigns';
const TABS = ['all', 'scheduled', 'live', 'ended'] as const;

export default function Index({ stores, storeCode, tab, counts, campaigns, next }: CampaignsPageData) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const name = useInLanguage();
    const list = useLoadMore<CampaignRowData>(campaigns, (campaign) => campaign.id);
    const more = (cursor: string) => list.more(LIST, { store: storeCode, tab, after: cursor }, ['campaigns', 'next']);
    const restoring = useKeptDepth(list, next, more);
    const newCampaign = `${LIST}/new`;
    const title = (row: CampaignRowData) => name(row.nameAr, row.nameEn ?? row.nameAr);
    const badge = (row: CampaignRowData) => <StateBadge state={row.overall} />;
    const storeStates = (row: CampaignRowData) => (
        <span className="flex flex-wrap gap-1" data-test="run-stores">
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
    // Per store, the sizes its run prices now: "SA 412 · AE 380", a dash for a run Pricing holds nothing of.
    const offers = (row: CampaignRowData) => (
        <bdi dir="ltr" className="tw-figure" data-test="run-offers">
            {row.stores.map((store) => `${store.code.toUpperCase()} ${store.offers === null ? '—' : figure(locale, store.offers)}`).join(' · ')}
        </bdi>
    );
    const dates = (row: CampaignRowData) => (
        <>
            {row.startsAt === null ? '—' : <Time value={row.startsAt} mode="absolute" focusable={false} />} – {row.endsAt === null ? '—' : <Time value={row.endsAt} mode="absolute" focusable={false} />}
        </>
    );

    return (
        <AdminLayout
            title={t('content::admin_campaigns.title')}
            subtitle={t('content::admin_campaigns.subtitle')}
            action={
                <Button asChild>
                    <Link href={newCampaign} data-test="new-campaign">
                        {t('content::admin_campaigns.new')}
                    </Link>
                </Button>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <StoreFilter stores={stores.map((store) => ({ code: store.code, name: store.name, isActive: store.isOn }))} value={storeCode} drop={['after', 'tab']} className="max-w-xs" />
                <div className="max-w-full overflow-x-auto">
                    <Tabs value={tab} onValueChange={(chosen) => router.get(LIST, { store: storeCode, tab: chosen }, { preserveScroll: true })}>
                        <TabsList variant="line">
                            {TABS.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`tab-${each}`}>
                                    {t(`content::admin_campaigns.tabs.${each}`)}
                                    <TabCount count={counts[each]} test={`count-${each}`} />
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                </div>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="campaigns-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_campaigns.empty')}</EmptyTitle>
                            <EmptyDescription>{t('content::admin_campaigns.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={newCampaign}>{t('content::admin_campaigns.new')}</Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{t('content::admin_campaigns.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('content::admin_campaigns.columns.name')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.columns.stores')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.columns.starts')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.columns.ends')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.columns.theme')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.columns.offers')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.columns.notice')}</TableHead>
                                        <TableHead>{t('content::admin_campaigns.columns.state')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <TableRow key={row.id} className="cursor-pointer" onClick={() => router.visit(`${LIST}/${row.id}`)} data-test={`campaign-${row.id}`}>
                                            <TableCell className="max-w-72 truncate text-label-14 text-ink">
                                                <Link href={`${LIST}/${row.id}`} onClick={(event) => event.stopPropagation()} className="hover:underline">
                                                    {title(row)}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{storeStates(row)}</TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{row.startsAt === null ? '—' : <Time value={row.startsAt} mode="absolute" focusable={false} />}</TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{row.endsAt === null ? '—' : <Time value={row.endsAt} mode="absolute" focusable={false} />}</TableCell>
                                            <TableCell className="max-w-48 truncate text-ink-muted">{row.themeAr === null ? '—' : name(row.themeAr, row.themeEn ?? row.themeAr)}</TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{offers(row)}</TableCell>
                                            <TableCell className="max-w-48 truncate text-ink-muted">{row.notice === null ? '—' : name(row.notice.titleAr, row.notice.titleEn)}</TableCell>
                                            <TableCell>{badge(row)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <PhoneCards
                            test="campaign"
                            cards={list.rows.map((row) => ({
                                id: row.id,
                                title: title(row),
                                badge: badge(row),
                                lines: [<span key="dates">{dates(row)}</span>, <span key="stores">{storeStates(row)}</span>, <span key="offers">{offers(row)}</span>],
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
