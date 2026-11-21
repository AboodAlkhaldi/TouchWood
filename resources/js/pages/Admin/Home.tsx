import { Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, FileText, RefreshCw } from 'lucide-react';
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { toast } from 'sonner';
import { AdminLayout } from '@/layouts/AdminLayout';
import { SelectField } from '@/components/Fields';
import { Money } from '@/components/Money';
import { Note } from '@/components/Note';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { colour } from '@/components/home/palette';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/cn';
import { figure, intlLocale } from '@/lib/digits';
import { fileSize } from '@/lib/file-size';
import { moneyAs } from '@/lib/money';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { HomeCardBlock, HomeFigureBlock, HomePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';
import type { HomeAction, HomeCell, HomeChange, HomeMetric, HomeSeries, HomeSplit, HomeStorePanel, HomeTable, HomeValue } from '@/types/generated/Modules/Platform/Public/Dto';
import type { MenuEntry, SharedProps } from '@/types/page';

/*
| Where the panel opens once somebody is signed in (frontend.md §2.2) - since build 7 the admin dashboard
| (dashboard.md) - on shadcn's parts with Geist's rules (§1.11).
|
| The **greeting row** (§2 A): "Good morning, Sara" by the store's clock (D1), Home's store switcher as built
| (the owner, 2026-10-06; access.md amendment 64: All Stores where offered, a Super Admin's off stores marked
| Off), the **period** (§3.3; This Month first, D9) and Today's Report where the reader may make it (§2.1) -
| both kept in the address beside the store (`/admin?store=sa&period=month`) - and when the figures were read,
| with Refresh (§7, D11: each card's figures are kept 5 minutes).
|
| Then what waits for this person: every menu entry with a count above nothing (frontend.md E7, owner
| 2026-09-29), one linked row per entry under one warning Note (owner, 2026-10-03).
|
| Then **the period's sections** (§2 B-I) as the modules register them (platform.md §2.6), laid out by their
| position and size, one column on a phone; and **Needs Attention** (§2 J): the cards with something to say.
| Only what this reader may see in the scope arrives.
|
| **The charts load just after the page shows** (§7): their code is fetched by its own import once the page is on
| screen, never on the server. Until it arrives each chart's place keeps its size, so nothing jumps; every chart's
| figures are on the page in words too, in a table only a screen reader reads (§4).
*/

type Props = HomePage;

type Charts = typeof import('@/components/home/charts');

const ChartsContext = createContext<Charts | null>(null);

/** How much of the row a card takes on a wide screen (dashboard.md §6.1); one column on a phone. */
const SPAN: Record<string, string> = {
    full: 'sm:col-span-2 lg:col-span-12',
    two_thirds: 'sm:col-span-2 lg:col-span-8',
    half: 'sm:col-span-2 lg:col-span-6',
    third: 'sm:col-span-2 lg:col-span-4',
    quarter: 'lg:col-span-3',
};

export default function Home({ storeCode, offersAllStores, stores, cards, period, periods, greeting, sections, actions, readAt }: Props) {
    const t = useTranslator();
    const { menu } = usePage<SharedProps>().props;
    const waiting: MenuEntry[] = menu.flatMap((group) => group.entries).filter((entry) => entry.count !== null && entry.count > 0);
    const [charts, setCharts] = useState<Charts | null>(null);

    useEffect(() => {
        let shown = true;
        void import('@/components/home/charts').then((loaded) => {
            if (shown) {
                setCharts(loaded);
            }
        });

        return () => {
            shown = false;
        };
    }, []);

    return (
        <AdminLayout
            title={t('admin.home.title')}
            subtitle={greeting}
            action={
                <div className="flex w-full flex-wrap items-end gap-3 sm:w-auto sm:flex-nowrap" data-test="home-greeting-row">
                    <StoreFilter stores={stores} value={storeCode} all={offersAllStores} className="w-full sm:w-48" />
                    <PeriodFilter value={period} periods={periods} />
                    {actions.map((card) => (card.action === null ? null : <ActionButton key={card.key} action={card.action} />))}
                </div>
            }
        >
            <ChartsContext.Provider value={charts}>
                <div className="grid gap-4">
                    {readAt === null ? null : (
                        <p className="flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted" data-test="home-updated">
                            <span>
                                {t('admin.home.updated')} <Time value={readAt} inSentence />
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                data-test="home-refresh"
                                onClick={() => router.post('/admin/home/refresh', { store: storeCode ?? '', period }, { preserveScroll: true })}
                            >
                                <RefreshCw aria-hidden="true" />
                                {t('admin.home.refresh')}
                            </Button>
                        </p>
                    )}

                    {waiting.length > 0 ? <Waiting entries={waiting} /> : null}

                    {sections.length > 0 ? (
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12" data-test="home-sections">
                            {sections.map((card) => (
                                <Section key={card.key} card={card} />
                            ))}
                        </div>
                    ) : null}

                    {cards.length > 0 ? (
                        <section className="grid gap-3" aria-labelledby="attention-label">
                            <h2 id="attention-label" className="text-heading-16 text-ink">
                                {t('admin.home.attention')}
                            </h2>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12" data-test="home-cards">
                                {cards.map((card) => (
                                    <HomeCard key={card.key} card={card} />
                                ))}
                            </div>
                        </section>
                    ) : null}

                    {sections.length === 0 && cards.length === 0 && waiting.length === 0 ? (
                        // Said only while nothing waits and no card shows: under them it would contradict them.
                        <Empty className="material-base">
                            <EmptyHeader>
                                <EmptyTitle>{t('admin.home.empty_title')}</EmptyTitle>
                                <EmptyDescription>{t('admin.home.empty')}</EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : null}
                </div>
            </ChartsContext.Provider>
        </AdminLayout>
    );
}

/** The period menu (§3.3), the same native select as the store filter, kept in the address. */
function PeriodFilter({ value, periods }: Pick<Props, 'periods'> & { value: string }) {
    const t = useTranslator();

    return (
        <SelectField
            id="period-filter"
            label={t('admin.home.period.label')}
            value={value}
            className="w-full sm:w-40"
            data-test="period-filter"
            onChange={(event) => {
                const params = new URLSearchParams(window.location.search);
                params.set('period', event.target.value);
                router.get(`${window.location.pathname}?${params.toString()}`, {}, { preserveScroll: true });
            }}
        >
            {periods.map((one) => (
                <NativeSelectOption key={one.value} value={one.value}>
                    {one.label}
                </NativeSelectOption>
            ))}
        </SelectField>
    );
}

/**
 * A greeting-row button a module registered (§2.1, Today's Report): it posts its fields asking for JSON and opens
 * the file the answer names; the action behind it asks its own permission again.
 */
function ActionButton({ action }: { action: HomeAction }) {
    const { csrfToken } = usePage<SharedProps>().props;
    const [busy, setBusy] = useState(false);

    const run = async () => {
        setBusy(true);

        try {
            const response = await fetch(action.href, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify(action.fields),
            });
            const answer = response.ok ? ((await response.json()) as { downloadUrl?: unknown }) : null;

            if (answer === null || typeof answer.downloadUrl !== 'string') {
                toast.error(action.failed);

                return;
            }

            window.location.assign(answer.downloadUrl);
        } catch {
            toast.error(action.failed);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Button type="button" variant="outline" disabled={busy} onClick={() => void run()} data-test="home-action">
            <FileText aria-hidden="true" />
            {busy ? action.busy : action.label}
        </Button>
    );
}

function Waiting({ entries }: { entries: MenuEntry[] }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;

    return (
        <section className="grid gap-3" role="status" aria-labelledby="waiting-label">
            <Note variant="warning" label={<span id="waiting-label">{t('admin.home.waiting_label')}</span>}>
                {t('admin.home.waiting_note')}
            </Note>

            <ItemGroup className="material-base">
                {entries.map((entry, index) => (
                    <div key={`${entry.module}.${entry.key}`} role="listitem">
                        {index === 0 ? null : <ItemSeparator />}
                        <Item asChild size="sm" className="rounded-none">
                            <Link href={entry.href} data-test={`waiting-${entry.module}.${entry.key}`}>
                                <ItemContent>
                                    <ItemTitle className="text-label-14 text-ink">{entry.label}</ItemTitle>
                                </ItemContent>
                                <ItemActions>
                                    {/* The number for the eye; the same said in words for a screen reader, as the sidebar says it. */}
                                    <Badge className={`tw-figure ${tone('amber-subtle')}`} aria-hidden="true">
                                        {figure(locale, entry.count ?? 0)}
                                    </Badge>
                                    <span className="sr-only">
                                        {t('admin.menu_waiting', {
                                            count: String(entry.count),
                                        })}
                                    </span>
                                    <ChevronRight aria-hidden="true" className="size-4 text-ink-muted rtl:rotate-180" />
                                </ItemActions>
                            </Link>
                        </Item>
                    </div>
                ))}
            </ItemGroup>
        </section>
    );
}

/** One of the period's sections, drawn by what it carries. */
function Section({ card }: { card: HomeCardBlock }) {
    return (
        <Card className={cn('material-base min-w-0 gap-0 border-0 py-0', SPAN[card.size] ?? SPAN.half)} data-test={`section-${card.key}`}>
            <CardHeader className="px-5 pt-5 pb-3">
                <CardTitle>
                    <h2 className="text-heading-16 text-ink">{card.title}</h2>
                </CardTitle>
                {card.href === null || card.openLabel === null ? null : (
                    <CardAction>
                        <Button asChild variant="outline" size="sm">
                            <Link href={card.href} data-test={`open-${card.key}`}>
                                {card.openLabel}
                            </Link>
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="grid min-w-0 gap-4 px-5 pb-5">
                {card.panels.length > 0 ? (
                    <Panels panels={card.panels} />
                ) : card.metrics.length === 1 && card.metrics[0] !== undefined ? (
                    <Figure metric={card.metrics[0]} />
                ) : (
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {card.metrics.map((metric) => (
                            <SmallFigure key={metric.label} metric={metric} />
                        ))}
                    </dl>
                )}
            </CardContent>
        </Card>
    );
}

/** A section's panels: one store's straight away, several behind small store tabs, the base store first (D8). */
function Panels({ panels }: { panels: HomeStorePanel[] }) {
    const t = useTranslator();
    const [first] = panels;

    if (first === undefined) {
        return null;
    }

    if (panels.length === 1) {
        return <PanelBody panel={first} />;
    }

    return (
        <Tabs defaultValue={first.storeCode} className="min-w-0 gap-3">
            <TabsList aria-label={t('admin.home.store_tabs')} className="h-auto max-w-full flex-wrap justify-start">
                {panels.map((panel) => (
                    <TabsTrigger key={panel.storeCode} value={panel.storeCode} data-test={`store-tab-${panel.storeCode}`}>
                        {panel.storeName}
                    </TabsTrigger>
                ))}
            </TabsList>
            {panels.map((panel) => (
                <TabsContent key={panel.storeCode} value={panel.storeCode} className="min-w-0">
                    <PanelBody panel={panel} />
                </TabsContent>
            ))}
        </Tabs>
    );
}

function PanelBody({ panel }: { panel: HomeStorePanel }) {
    return (
        <div className="grid min-w-0 gap-4" data-test={`panel-${panel.storeCode}`}>
            {panel.series === null ? null : <SeriesBlock series={panel.series} />}
            {panel.splits.length === 0 ? null : (
                <div className={cn('grid min-w-0 gap-6', panel.splits.length > 1 && 'lg:grid-cols-2')}>
                    {panel.splits.map((split) => (split.shape === 'ring' ? <RingBlock key={split.label} split={split} /> : <BarBlock key={split.label} split={split} />))}
                </div>
            )}
            {panel.table === null ? null : <TableBlock table={panel.table} />}
        </div>
    );
}

/** The decimals an amount is written with, from one the server wrote ("0.00 SAR": 2). */
function decimalsOf(text: string): number {
    return /\.(\d+)/.exec(text)?.[1]?.length ?? 0;
}

function pointLabel(at: string, locale: string, byMonth: boolean): string {
    const day = new Date(byMonth ? `${at}-01T00:00:00Z` : `${at}T00:00:00Z`);

    return Number.isNaN(day.getTime())
        ? at
        : new Intl.DateTimeFormat(intlLocale(locale), byMonth ? { month: 'short', year: 'numeric', timeZone: 'UTC' } : { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
              day,
          );
}

/** Sales and orders by day (§2 C): the chart once loaded, its place kept until then, and its figures in words. */
function SeriesBlock({ series }: { series: HomeSeries }) {
    const t = useTranslator();
    const charts = useContext(ChartsContext);
    const { locale, direction } = usePage<SharedProps>().props;
    const lines = Object.fromEntries(series.lines.map((line) => [line.key, line]));
    const moneyLine = series.lines.find((line) => line.money !== null);
    const decimals = moneyLine?.money ? decimalsOf(moneyLine.money.text) : 0;
    const compact = new Intl.NumberFormat(intlLocale(locale), {
        notation: 'compact',
        maximumFractionDigits: 1,
    });
    const written = (key: string, value: number | null | undefined): string => {
        const line = lines[key];

        if (value === null || value === undefined) {
            return '—';
        }

        return line?.money ? moneyAs(line.money, BigInt(Math.round(value))).text : figure(locale, value);
    };

    return (
        <figure className="grid min-w-0 gap-2" data-test="home-series">
            {charts === null ? (
                <Skeleton className="h-64 w-full" data-test="chart-waiting" />
            ) : (
                <charts.SeriesChart
                    series={series}
                    formats={{
                        at: (at) => pointLabel(at, locale, series.byMonth),
                        value: (key, value) => written(key, value),
                        moneyTick: (value) => compact.format(value / 10 ** decimals),
                        countTick: (value) => compact.format(value),
                        rtl: direction === 'rtl',
                    }}
                />
            )}
            {/* sr-only on a wrapper: a table grows to its cells whatever width it is given, and pushed a phone's page sideways. */}
            <div className="sr-only">
                <table>
                    <caption>{series.lines.map((line) => line.label).join(' · ')}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{series.byMonth ? t('admin.home.month') : t('admin.home.day')}</th>
                            {series.lines.map((line) => (
                                <th key={line.key} scope="col">
                                    {line.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {series.points.map((point) => (
                            <tr key={point.at}>
                                <th scope="row">{pointLabel(point.at, locale, series.byMonth)}</th>
                                {series.lines.map((line) => (
                                    <td key={line.key}>{written(line.key, point.values[line.key])}</td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </figure>
    );
}

/** A part's swatch and words, with its value, share and change - the legend a split's drawing is read by. */
function SplitParts({ split }: { split: HomeSplit }) {
    const { locale } = usePage<SharedProps>().props;

    return (
        <ul className="grid gap-2">
            {split.parts.map((part, index) => (
                <li key={part.label} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-copy-14">
                    <span className="flex min-w-0 items-center gap-2">
                        <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: colour(index) }} />
                        <span className="truncate text-ink">{part.label}</span>
                    </span>
                    <span className="flex items-center gap-2">
                        <Value value={part.value} className="text-label-14 text-ink" />
                        <span className="tw-figure text-copy-13 text-ink-muted">{share(locale, part.share)}</span>
                        <Change change={part.change} />
                    </span>
                </li>
            ))}
        </ul>
    );
}

function RingBlock({ split }: { split: HomeSplit }) {
    const charts = useContext(ChartsContext);
    const total = split.total?.money?.text ?? '';

    return (
        <figure className="grid min-w-0 gap-3" aria-label={split.label} data-test="home-ring">
            {charts === null ? <Skeleton className="mx-auto size-48 rounded-full" data-test="chart-waiting" /> : <charts.RingChart split={split} total={total} />}
            <SplitParts split={split} />
        </figure>
    );
}

function BarBlock({ split }: { split: HomeSplit }) {
    const t = useTranslator();
    const charts = useContext(ChartsContext);
    const { direction } = usePage<SharedProps>().props;

    return (
        <figure className="grid min-w-0 gap-3" aria-labelledby={`split-${split.label}`} data-test="home-bar">
            <figcaption id={`split-${split.label}`} className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-14 text-ink">{split.label}</span>
                {split.total === null ? null : <Value value={split.total} className="text-label-14 text-ink-muted" />}
            </figcaption>
            {split.parts.length === 0 ? (
                <p className="text-copy-13 text-ink-muted">{t('admin.home.nothing')}</p>
            ) : (
                <>
                    {charts === null ? <Skeleton className="h-8 w-full" data-test="chart-waiting" /> : <charts.SplitBarChart split={split} rtl={direction === 'rtl'} />}
                    <SplitParts split={split} />
                </>
            )}
        </figure>
    );
}

function TableBlock({ table }: { table: HomeTable }) {
    const [first, ...rest] = table.columns;

    if (table.rows.length === 0) {
        return <p className="text-copy-14 text-ink-muted">{table.empty}</p>;
    }

    return (
        <Table data-test="home-table">
            <TableHeader>
                <TableRow>
                    <TableHead>{first?.label}</TableHead>
                    {rest.map((column) => (
                        <TableHead key={column.label} className={column.end ? 'text-end' : undefined}>
                            {column.label}
                        </TableHead>
                    ))}
                </TableRow>
            </TableHeader>
            <TableBody>
                {table.rows.map((row, index) => (
                    <TableRow key={`${row.title}-${index}`}>
                        <TableCell>
                            <div className="flex min-w-48 items-center gap-3">
                                {row.photo === null ? (
                                    <span aria-hidden="true" className="size-10 shrink-0 rounded-md bg-surface-sunken" />
                                ) : (
                                    <img src={row.photo} alt="" width={40} height={40} loading="lazy" className="size-10 shrink-0 rounded-md object-cover" />
                                )}
                                <span className="grid min-w-0">
                                    {row.href === null ? (
                                        <span className="truncate text-label-14 text-ink">{row.title}</span>
                                    ) : (
                                        <Link href={row.href} className="truncate text-label-14 text-ink hover:underline hover:underline-offset-4" data-test="home-table-row">
                                            {row.title}
                                        </Link>
                                    )}
                                    {row.detail === null ? null : <span className="truncate text-copy-13 text-ink-muted">{row.detail}</span>}
                                </span>
                            </div>
                        </TableCell>
                        {row.cells.map((cell, column) => (
                            <TableCell key={column} className={rest[column]?.end ? 'text-end' : undefined}>
                                <Cell cell={cell} />
                            </TableCell>
                        ))}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

function Cell({ cell }: { cell: HomeCell }) {
    if (cell.value !== null) {
        return <Value value={cell.value} />;
    }

    if (cell.badge !== null && cell.text !== null) {
        return <Badge className={tone(cell.badge as Tone)}>{cell.text}</Badge>;
    }

    return <span>{cell.text}</span>;
}

/** A value as the panel writes it: money as the server wrote it, a count or a rating in Latin digits; "—" for none. */
function Value({ value, className }: { value: HomeValue | null; className?: string }) {
    const { locale } = usePage<SharedProps>().props;

    if (value === null) {
        return <span className={cn('tw-figure', className)}>—</span>;
    }

    if (value.money !== null) {
        return <Money value={value.money} className={className} />;
    }

    const written =
        value.unit === 'rating'
            ? new Intl.NumberFormat(intlLocale(locale), {
                  minimumFractionDigits: 1,
                  maximumFractionDigits: 1,
              }).format(value.value)
            : figure(locale, value.value);

    return <span className={cn('tw-figure', className)}>{written}</span>;
}

function share(locale: string, percent: number): string {
    return `${new Intl.NumberFormat(intlLocale(locale), { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(percent)}%`;
}

/** A figure's change against the period before (§3.4): ▲ or ▼ in the good or bad colour, "New" from nothing. */
function Change({ change }: { change: HomeChange | null }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;

    if (change === null) {
        return null;
    }

    if (change.percent === null) {
        return (
            <Badge className={tone('blue-subtle')} data-test="change-new">
                {t('admin.home.change.new')}
            </Badge>
        );
    }

    const up = change.percent > 0;
    const down = change.percent < 0;
    const amount = share(locale, Math.abs(change.percent));

    return (
        <span className={cn('tw-figure text-label-13', up ? 'text-good' : down ? 'text-bad' : 'text-ink-muted')} data-test="change">
            <span aria-hidden="true">
                {up ? '▲ ' : down ? '▼ ' : ''}
                {amount}
            </span>
            <span className="sr-only">{t(up ? 'admin.home.change.up' : down ? 'admin.home.change.down' : 'admin.home.change.none', { percent: amount })}</span>
        </span>
    );
}

/** A figure card (§2 B, F): each store's figure, its change and small trend line, a total for a count. */
function Figure({ metric }: { metric: HomeMetric }) {
    const t = useTranslator();
    const charts = useContext(ChartsContext);
    const many = metric.lines.length > 1;

    return (
        <div className="grid gap-3" data-test="home-figure">
            {metric.lines.map((line, index) => (
                <div key={line.store ?? index} className="grid gap-1">
                    {line.store === null ? null : <span className="text-label-13 text-ink-muted">{line.store}</span>}
                    <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <Linked href={metric.href}>
                            <Value value={line.value} className={many ? 'text-heading-20 text-ink' : 'text-heading-24 text-ink'} />
                        </Linked>
                        <Change change={line.change} />
                    </div>
                    {line.note === null ? null : <span className="text-copy-13 text-ink-muted">{line.note}</span>}
                    {line.trend.length < 2 ? null : charts === null ? <Skeleton className="h-10 w-full" data-test="chart-waiting" /> : <charts.Sparkline values={line.trend} />}
                </div>
            ))}
            {metric.total === null ? null : (
                <div className="flex flex-wrap items-baseline gap-x-3 border-t border-line pt-2">
                    <span className="text-label-13 text-ink-muted">{t('admin.home.total')}</span>
                    <Value value={metric.total.value} className="text-heading-20 text-ink" />
                    <Change change={metric.total.change} />
                </div>
            )}
        </div>
    );
}

/** One of several small figures in a section (§2 E): its words, its figure - a line per store - and its change. */
function SmallFigure({ metric }: { metric: HomeMetric }) {
    const t = useTranslator();
    const shown = metric.total ?? metric.lines[0] ?? null;

    return (
        <div className="grid content-start gap-1 rounded-md border border-line px-3 py-2.5" data-test="home-small-figure">
            <dt className="text-label-13 text-ink-muted">{metric.label}</dt>
            <dd className="grid gap-1">
                <span className="flex flex-wrap items-baseline gap-x-2">
                    <Linked href={metric.href}>
                        <Value value={shown?.value ?? null} className="text-heading-24 text-ink" />
                    </Linked>
                    <Change change={shown?.change ?? null} />
                </span>
                {metric.lines.length > 1
                    ? metric.lines.map((line, index) => (
                          <span key={line.store ?? index} className="flex justify-between gap-2 text-copy-13 text-ink-muted">
                              <span className="truncate">{line.store}</span>
                              <Value value={line.value} />
                          </span>
                      ))
                    : null}
                {metric.total === null ? null : <span className="sr-only">{t('admin.home.total')}</span>}
            </dd>
        </div>
    );
}

function Linked({ href, children }: { href: string | null; children: ReactNode }) {
    return href === null ? (
        <>{children}</>
    ) : (
        <Link href={href} className="rounded-sm hover:underline hover:underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none">
            {children}
        </Link>
    );
}

/** One of Needs Attention's cards: its figures, its short list, and the way to its whole screen. */
function HomeCard({ card }: { card: HomeCardBlock }) {
    return (
        <Card className={cn('material-base min-w-0 gap-0 border-0 py-0', SPAN[card.size] ?? SPAN.half)} data-test={`card-${card.key}`}>
            <CardHeader className="px-5 pt-5 pb-3">
                <CardTitle>
                    <h3 className="text-heading-16 text-ink">{card.title}</h3>
                </CardTitle>
                {card.href === null || card.openLabel === null ? null : (
                    <CardAction>
                        <Button asChild variant="outline" size="sm">
                            <Link href={card.href} data-test={`open-${card.key}`}>
                                {card.openLabel}
                            </Link>
                        </Button>
                    </CardAction>
                )}
            </CardHeader>

            <CardContent className="grid gap-4 px-5 pb-5">
                {card.figures.length === 0 ? null : (
                    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {/* A card's figures are a fixed set, so their place is their key. */}
                        {card.figures.map((one, index) => (
                            <CountFigure key={index} figureBlock={one} />
                        ))}
                    </dl>
                )}

                {card.rows.length === 0 ? null : (
                    <section className="grid gap-1.5" aria-label={card.rowsLabel ?? undefined}>
                        {card.rowsLabel === null ? null : <h4 className="text-label-13 text-ink-muted">{card.rowsLabel}</h4>}
                        <ItemGroup className="rounded-md border border-line">
                            {card.rows.map((row, index) => (
                                <div key={`${row.label}-${index}`} role="listitem">
                                    {index === 0 ? null : <ItemSeparator />}
                                    {row.href === null ? (
                                        <Item size="sm" className="rounded-none">
                                            <RowContent label={row.label} detail={row.detail} at={row.at} linked={false} />
                                        </Item>
                                    ) : (
                                        <Item asChild size="sm" className="rounded-none">
                                            <Link href={row.href} data-test="home-row">
                                                <RowContent label={row.label} detail={row.detail} at={row.at} linked />
                                            </Link>
                                        </Item>
                                    )}
                                </div>
                            ))}
                        </ItemGroup>
                    </section>
                )}
            </CardContent>
        </Card>
    );
}

function RowContent({ label, detail, at, linked }: { label: string; detail: string | null; at: string | null; linked: boolean }) {
    return (
        <>
            <ItemContent>
                <ItemTitle className="text-label-14 text-ink">{label}</ItemTitle>
                {detail === null ? null : <ItemDescription className="text-copy-13 text-ink-muted">{detail}</ItemDescription>}
            </ItemContent>
            <ItemActions>
                {/* Not focusable inside a row that is itself a link (Time's own rule). */}
                {at === null ? null : (
                    <span className="text-copy-13 text-ink-muted">
                        <Time value={at} focusable={!linked} />
                    </span>
                )}
                {linked ? <ChevronRight aria-hidden="true" className="size-4 text-ink-muted rtl:rotate-180" /> : null}
            </ItemActions>
        </>
    );
}

/** A label and its number (Geist's Stat look): in Latin digits, a size written as a size. */
function CountFigure({ figureBlock }: { figureBlock: HomeFigureBlock }) {
    const { locale } = usePage<SharedProps>().props;
    const value = figureBlock.unit === 'bytes' ? fileSize(figureBlock.value, locale) : figure(locale, figureBlock.value);
    const number = <span className={cn('text-heading-24 tabular-nums', figureBlock.tone === 'amber' && figureBlock.value > 0 ? 'text-warn' : 'text-ink')}>{value}</span>;

    return (
        <div className="grid gap-0.5 rounded-md border border-line px-3 py-2.5">
            <dt className="text-label-13 text-ink-muted">{figureBlock.label}</dt>
            <dd>
                {figureBlock.href === null ? (
                    number
                ) : (
                    <Link
                        href={figureBlock.href}
                        className="rounded-sm hover:underline hover:underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        {number}
                    </Link>
                )}
            </dd>
        </div>
    );
}
