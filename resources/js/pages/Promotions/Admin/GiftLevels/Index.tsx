import { lazy, Suspense, useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { StoreFilter } from '@/components/StoreFilter';
import { Time, useMoments } from '@/components/Time';
import { Amount, Unknown } from '@/components/promotions/parts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { GiftLevelData, GiftLevelsPage } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M4 - Marketing › Gift Levels (promotions.md §4.1): the store's levels in one list, highest first
| (owner's Q10), with the line that says how an order gets its gift (§1.7). A manager (Q16) has New
| Level…, Edit… and Turn Off / Turn On per row; a level is never deleted, only switched off (§3).
*/

// The dialog loads when it is first opened: it is not part of the page's first paint (frontend.md §5).
const GiftLevelDialog = lazy(() => import('./GiftLevelDialog').then((module) => ({ default: module.GiftLevelDialog })));

const STATE_LOOK: Record<string, Tone> = { GIVING: 'green-subtle', NOT_STARTED: 'blue-subtle', ENDED: 'gray-subtle', OFF: 'gray-subtle' };

export default function Index(page: GiftLevelsPage) {
    const t = useTranslator();
    const [editing, setEditing] = useState<GiftLevelData | 'new' | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const moments = useMoments();
    const newButton = (test: string) =>
        page.mayManage ? (
            <Button type="button" onClick={() => setEditing('new')} data-test={test}>
                {`${t('promotions::admin_gifts.list.new')}…`}
            </Button>
        ) : null;

    return (
        <AdminLayout title={t('promotions::admin_gifts.list.title')} subtitle={t('promotions::admin_gifts.list.subtitle')} action={newButton(`new-level`) ?? undefined}>
            <div className="grid gap-4">
                <FormError />
                <div className="grid sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]">
                    <div className="sm:col-start-2">
                        <StoreFilter stores={page.stores} value={page.storeCode} />
                    </div>
                </div>

                {page.levels.length === 0 ? (
                    <Empty className="material-base" data-test="levels-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('promotions::admin_gifts.list.empty_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::admin_gifts.list.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        {page.mayManage ? <EmptyContent>{newButton(`empty-new-level`)}</EmptyContent> : null}
                    </Empty>
                ) : (
                    <>
                        <div className="material-base max-w-full overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('promotions::admin_gifts.list.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('promotions::admin_gifts.columns.from')}</TableHead>
                                        <TableHead>{t('promotions::admin_gifts.columns.gift')}</TableHead>
                                        <TableHead>{t('promotions::admin_gifts.columns.who')}</TableHead>
                                        <TableHead>{t('promotions::admin_gifts.columns.dates')}</TableHead>
                                        <TableHead>{t('promotions::admin_gifts.columns.state')}</TableHead>
                                        {page.mayManage ? <TableHead className="text-end">{t('promotions::admin_gifts.columns.actions')}</TableHead> : null}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {page.levels.map((level) => (
                                        <TableRow key={level.id} data-test={`level-${level.id}`}>
                                            <TableCell className="whitespace-nowrap">
                                                <Amount value={level.amount} />
                                            </TableCell>
                                            <TableCell className="min-w-40">
                                                <span className="block text-label-14 text-ink">{level.productName ?? t('promotions::admin_gifts.list.gone')}</span>
                                                <span className="flex flex-wrap items-center gap-2">
                                                    {level.variantCode === null ? null : (
                                                        <bdi dir="ltr" className="font-mono text-copy-13 text-ink-muted">
                                                            {level.variantCode}
                                                        </bdi>
                                                    )}
                                                    {level.archived ? (
                                                        <Badge className={tone('amber-subtle')} data-test="archived">
                                                            {t('promotions::admin_gifts.list.archived')}
                                                        </Badge>
                                                    ) : null}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-ink-muted">{t(`promotions::admin_gifts.who.${level.forPublic && level.forCompany ? 'both' : level.forPublic ? 'individuals' : 'companies'}`)}</TableCell>
                                            <TableCell className="text-copy-13 text-ink-muted">
                                                {level.startsAt === null && level.endsAt === null ? (
                                                    t('promotions::admin_gifts.list.always')
                                                ) : (
                                                    <>
                                                        <span className="block whitespace-nowrap">{level.startsAt === null ? <Unknown /> : <Time value={level.startsAt} />}</span>
                                                        <span className="block whitespace-nowrap">
                                                            {level.endsAt === null ? (
                                                                t('promotions::admin_gifts.list.no_end')
                                                            ) : (
                                                                t('promotions::admin_gifts.list.until', { date: isolate(moments.date(level.endsAt)) })
                                                            )}
                                                        </span>
                                                    </>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={tone(STATE_LOOK[level.state] ?? 'gray-subtle')} data-test="level-state">
                                                    {t(`promotions::admin_gifts.state.${level.state}`)}
                                                </Badge>
                                            </TableCell>
                                            {page.mayManage ? (
                                                <TableCell className="text-end">
                                                    <div className="flex justify-end gap-2">
                                                        <Button type="button" size="sm" variant="outline" aria-label={t('promotions::admin_gifts.list.edit_named', { amount: isolate(level.amountText) })} onClick={() => setEditing(level)} data-test={`edit-${level.id}`}>
                                                            {`${t('promotions::admin_gifts.list.edit')}…`}
                                                        </Button>
                                                        <ActionButton
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            loading={busy === level.id}
                                                            aria-label={t(level.active ? 'promotions::admin_gifts.list.turn_off_named' : 'promotions::admin_gifts.list.turn_on_named', { amount: isolate(level.amountText) })}
                                                            data-test={`switch-${level.id}`}
                                                            onClick={() =>
                                                                router.post(`/admin/gift-levels/${level.id}/switch`, { active: !level.active }, { preserveScroll: true, onStart: () => setBusy(level.id), onFinish: () => setBusy(null) })
                                                            }
                                                        >
                                                            {level.active ? t('promotions::admin_gifts.list.turn_off') : t('promotions::admin_gifts.list.turn_on')}
                                                        </ActionButton>
                                                    </div>
                                                </TableCell>
                                            ) : null}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <p className="text-copy-13 text-ink-muted" data-test="levels-rule">
                            {t('promotions::admin_gifts.list.rule')}
                        </p>
                    </>
                )}
            </div>

            {editing === null ? null : (
                <Suspense fallback={null}>
                    <GiftLevelDialog open onOpenChange={(open) => (open ? undefined : setEditing(null))} page={page} level={editing === 'new' ? undefined : editing} />
                </Suspense>
            )}
        </AdminLayout>
    );
}
