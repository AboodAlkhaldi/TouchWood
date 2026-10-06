import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Note } from '@/components/Note';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { cn } from '@/lib/cn';
import { figure } from '@/lib/digits';
import { fileSize } from '@/lib/file-size';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { HomeCardBlock, HomeFigureBlock, HomePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';
import type { MenuEntry, SharedProps } from '@/types/page';

/*
| Where the panel opens once somebody is signed in (frontend.md §2.2), on shadcn's parts with
| Geist's rules (§1.11).
|
| First what waits for this person: every menu entry with a count above nothing - the failed jobs
| first of all (frontend.md E7, owner 2026-09-29) - one linked row per entry under one warning Note
| (owner, 2026-10-03), which stays until nothing waits.
|
| Then the **cards** the modules register (platform.md §2.6; the owner's fix list, point 6): each a
| shadcn Card with its figures - a label and a number in Latin digits, a size as a size - an
| optional short list, and a link to its whole screen. Only the cards this reader may see in the
| scope arrive; a module built later adds its own without this page changing.
|
| The **store switcher** (the owner, 2026-10-06; access.md amendment 64) sits in the page's action
| slot and changes the figures only: All Stores - for a reader whose reach covers every store for a
| card, a Super Admin always - then each of the person's stores, a Super Admin's off ones marked Off;
| one store and no All Stores means no switcher. The same store filter as every store screen
| (StoreFilter), so the choice is in the address (`/admin?store=sa`): a reload or a shared link keeps
| it.
*/

type Props = HomePage;

export default function Home({ storeCode, offersAllStores, stores, cards }: Props) {
    const t = useTranslator();
    const { menu, locale } = usePage<SharedProps>().props;
    const waiting: MenuEntry[] = menu.flatMap((group) => group.entries).filter((entry) => entry.count !== null && entry.count > 0);

    return (
        <AdminLayout
            title={t('admin.home.title')}
            subtitle={t('admin.home.subtitle')}
            action={<StoreFilter stores={stores} value={storeCode} all={offersAllStores} className="min-w-48" />}
        >
            <div className="grid gap-4">
                {waiting.length > 0 ? (
                    <section className="grid gap-3" role="status" aria-labelledby="waiting-label">
                        <Note variant="warning" label={<span id="waiting-label">{t('admin.home.waiting_label')}</span>}>
                            {t('admin.home.waiting_note')}
                        </Note>

                        <ItemGroup className="material-base">
                            {waiting.map((entry, index) => (
                                <div key={`${entry.module}.${entry.key}`} role="listitem">
                                    {index === 0 ? null : <ItemSeparator />}
                                    <Item asChild size="sm" className="rounded-none">
                                        <Link href={entry.href} data-test={`waiting-${entry.module}.${entry.key}`}>
                                            <ItemContent>
                                                <ItemTitle className="text-label-14 text-ink">{entry.label}</ItemTitle>
                                            </ItemContent>
                                            <ItemActions>
                                                {/* The number for the eye; the same said in words for a screen reader,
                                                    as the sidebar says it. */}
                                                <Badge className={`tw-figure ${tone('amber-subtle')}`} aria-hidden="true">
                                                    {figure(locale, entry.count ?? 0)}
                                                </Badge>
                                                <span className="sr-only">{t('admin.menu_waiting', { count: String(entry.count) })}</span>
                                                <ChevronRight aria-hidden="true" className="size-4 text-ink-muted rtl:rotate-180" />
                                            </ItemActions>
                                        </Link>
                                    </Item>
                                </div>
                            ))}
                        </ItemGroup>
                    </section>
                ) : null}

                {cards.length > 0 ? (
                    <div className="grid gap-4 lg:grid-cols-2" data-test="home-cards">
                        {cards.map((card) => (
                            <HomeCard key={card.key} card={card} />
                        ))}
                    </div>
                ) : waiting.length === 0 ? (
                    // Said only while nothing waits and no card shows: under them it would contradict them.
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle>{t('admin.home.empty_title')}</EmptyTitle>
                            <EmptyDescription>{t('admin.home.empty')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : null}
            </div>
        </AdminLayout>
    );
}

/** One module's card: its figures, its short list, and the way to its whole screen. */
function HomeCard({ card }: { card: HomeCardBlock }) {
    return (
        <Card className="material-base gap-0 border-0 py-0" data-test={`card-${card.key}`}>
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

            <CardContent className="grid gap-4 px-5 pb-5">
                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {/* A card's figures are a fixed set, so their place is their key. */}
                    {card.figures.map((one, index) => (
                        <Figure key={index} figureBlock={one} />
                    ))}
                </dl>

                {card.rows.length === 0 ? null : (
                    <section className="grid gap-1.5" aria-label={card.rowsLabel ?? undefined}>
                        {card.rowsLabel === null ? null : <h3 className="text-label-13 text-ink-muted">{card.rowsLabel}</h3>}
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
function Figure({ figureBlock }: { figureBlock: HomeFigureBlock }) {
    const { locale } = usePage<SharedProps>().props;
    const value = figureBlock.unit === 'bytes' ? fileSize(figureBlock.value, locale) : figure(locale, figureBlock.value);
    const number = (
        <span className={cn('text-heading-24 tabular-nums', figureBlock.tone === 'amber' && figureBlock.value > 0 ? 'text-warn' : 'text-ink')}>{value}</span>
    );

    return (
        <div className="grid gap-0.5 rounded-md border border-line px-3 py-2.5">
            <dt className="text-label-13 text-ink-muted">{figureBlock.label}</dt>
            <dd>
                {figureBlock.href === null ? (
                    number
                ) : (
                    <Link href={figureBlock.href} className="rounded-sm hover:underline hover:underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none">
                        {number}
                    </Link>
                )}
            </dd>
        </div>
    );
}
