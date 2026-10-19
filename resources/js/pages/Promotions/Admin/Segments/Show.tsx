import { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { FormError, useFreshRefusal } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { useLeaveGuard } from '@/components/SaveBar';
import { SearchField } from '@/components/SearchField';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Time } from '@/components/Time';
import { plural, Unknown, useLocale } from '@/components/promotions/parts';
import { useRuleWords } from '@/components/promotions/segments';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { isolate } from '@/lib/bidi';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import { SegmentDialog } from '@/pages/Promotions/Admin/Segments/SegmentDialog';
import type { CustomerNameData, MemberData, SegmentPage, SegmentRowData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M3 - a segment's page (promotions.md §4.1): its facts and its members, 50 at a time with Show More.
| A manager (Q16) has Edit…, Refresh Now for a rule segment, and Delete Segment…; for a segment picked
| by hand (Q8), Add Customers… searches the store's customers by name, email or phone, each member has
| Remove, and Save Members sends the changes - out of reach until there are some.
*/

const LIST = '/admin/segments';

export default function Show(page: SegmentPage) {
    const t = useTranslator();
    const segment = page.segment;
    const picking = page.mayManage && segment.type === 'STATIC';

    return (
        <AdminLayout
            title={segment.name}
            subtitle={page.storeName}
            breadcrumbs={[{ label: t('promotions::admin_segments.list.title'), href: `${LIST}?store=${encodeURIComponent(page.storeCode)}` }]}
            action={page.mayManage ? <HeaderActions segment={segment} storeCode={page.storeCode} /> : undefined}
        >
            <div className="grid gap-4">
                <FormError />
                {page.storeIsActive ? null : (
                    <div>
                        <StoreOffBadge />
                    </div>
                )}
                <Facts segment={segment} />
                {picking ? <PickedMembers page={page} /> : <Members page={page} />}
            </div>
        </AdminLayout>
    );
}

function HeaderActions({ segment, storeCode }: { segment: SegmentRowData; storeCode: string }) {
    const t = useTranslator();
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const remove = useForm({});
    const deleteButton = useRef<HTMLButtonElement>(null);
    const refusal = useFreshRefusal(deleting);

    return (
        <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={() => setEditing(true)} data-test="edit-segment">
                {`${t('promotions::admin_segments.show.edit')}…`}
            </Button>
            {segment.type === 'DYNAMIC' ? (
                <ActionButton
                    type="button"
                    variant="outline"
                    loading={refreshing}
                    data-test="refresh-segment"
                    onClick={() => router.post(`${LIST}/${segment.id}/refresh`, {}, { preserveScroll: true, onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) })}
                >
                    {t('promotions::admin_segments.show.refresh')}
                </ActionButton>
            ) : null}
            <Button ref={deleteButton} type="button" variant="outline" className="text-bad" onClick={() => setDeleting(true)} data-test="delete-segment">
                {`${t('promotions::admin_segments.show.delete')}…`}
            </Button>
            <SegmentDialog open={editing} onOpenChange={setEditing} storeCode={storeCode} segment={segment} />
            <DestructiveActionDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={t('promotions::admin_segments.show.delete_title')}
                confirmLabel={t('promotions::admin_segments.show.delete_title')}
                description={t('promotions::admin_segments.show.delete_body', { name: isolate(segment.name) })}
                irreversibleDescription={t('promotions::admin_segments.show.delete_irreversible', { name: isolate(segment.name) })}
                verificationPhrase={segment.name}
                verificationLabel={t('promotions::admin_segments.show.verification_label')}
                loading={remove.processing}
                error={refusal}
                onConfirm={() => remove.post(`${LIST}/${segment.id}/delete`, { onSuccess: () => setDeleting(false) })}
                returnFocusTo={deleteButton}
            />
        </div>
    );
}

function Facts({ segment }: { segment: SegmentRowData }) {
    const t = useTranslator();
    const locale = useLocale();
    const rule = useRuleWords();
    const facts: [string, React.ReactNode][] = [
        [t('promotions::admin_segments.columns.type'), t(`promotions::admin_segments.type.${segment.type}`)],
        [t('promotions::admin_segments.columns.who'), t(`promotions::admin_segments.audience.${segment.audience}`)],
        [t('promotions::admin_segments.columns.rule'), rule(segment) ?? <Unknown />],
        [
            t('promotions::admin_segments.columns.members'),
            <bdi key="members" dir="ltr" className="tw-figure">
                {figure(locale, segment.memberCount)}
            </bdi>,
        ],
        [t('promotions::admin_segments.columns.updated'), segment.refreshedAt === null ? <Unknown /> : <Time value={segment.refreshedAt} />],
    ];

    return (
        <Card className="material-base border-0 py-4">
            <CardContent className="px-5">
                <dl className="grid gap-4 sm:grid-cols-5" data-test="segment-facts">
                    {facts.map(([label, value]) => (
                        <div key={label} className="grid gap-1">
                            <dt className="text-copy-13 text-ink-muted">{label}</dt>
                            <dd className="text-label-14 text-ink">{value}</dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

function Name({ customer }: { customer: CustomerNameData }) {
    const t = useTranslator();

    return customer.deleted ? <span className="text-ink-muted">{t('promotions::admin_segments.show.deleted_customer')}</span> : <>{customer.name}</>;
}

/** A rule segment's members, or a hand-picked one's for a reader who may not pick: read only. */
function Members({ page }: { page: SegmentPage }) {
    const t = useTranslator();
    const list = useLoadMore(page.members, (row: MemberData) => row.customer.id);

    return (
        <section className="grid gap-3" aria-labelledby="members-title">
            <h2 id="members-title" className="text-heading-16 text-ink">
                {t('promotions::admin_segments.show.members')}
            </h2>
            {list.rows.length === 0 ? (
                <Empty className="material-base" data-test="members-empty">
                    <EmptyHeader>
                        <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::admin_segments.show.no_members')}</EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <MembersTable rows={list.rows} />
            )}
            {page.nextMember !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(`${LIST}/${page.segment.id}`, { after_member: page.nextMember ?? '' }, ['members', 'nextMember'])} /> : null}
        </section>
    );
}

function MembersTable({ rows, removed, onToggle }: { rows: MemberData[]; removed?: Set<string>; onToggle?: (row: MemberData) => void }) {
    const t = useTranslator();

    return (
        <div className="material-base max-w-full overflow-x-auto">
            <Table>
                <TableCaption className="sr-only">{t('promotions::admin_segments.show.members')}</TableCaption>
                <TableHeader className="bg-surface-sunken">
                    <TableRow>
                        <TableHead>{t('promotions::admin_segments.show.col_customer')}</TableHead>
                        <TableHead>{t('promotions::admin_segments.show.col_added')}</TableHead>
                        {onToggle === undefined ? null : <TableHead className="text-end">{t('promotions::admin_segments.show.col_actions')}</TableHead>}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => {
                        const out = removed?.has(row.customer.id) ?? false;

                        return (
                            <TableRow key={row.customer.id} data-test={`member-${row.customer.id}`}>
                                <TableCell className={out ? 'text-ink-muted line-through' : undefined}>
                                    <Name customer={row.customer} />
                                </TableCell>
                                <TableCell className="text-copy-13 whitespace-nowrap text-ink-muted">{row.addedAt === null ? <Unknown /> : <Time value={row.addedAt} />}</TableCell>
                                {onToggle === undefined ? null : (
                                    <TableCell className="text-end">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            aria-pressed={out}
                                            aria-label={t('promotions::admin_segments.show.remove_named', { name: row.customer.name ?? t('promotions::admin_segments.show.deleted_customer') })}
                                            onClick={() => onToggle(row)}
                                            data-test={`toggle-${row.customer.id}`}
                                        >
                                            {out ? t('promotions::admin_segments.show.keep') : t('promotions::admin_segments.show.remove')}
                                        </Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

/** A segment picked by hand, for a manager (Q8): Add Customers…, Remove per member, Save Members. */
function PickedMembers({ page }: { page: SegmentPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const list = useLoadMore(page.members, (row: MemberData) => row.customer.id);
    const [added, setAdded] = useState<MemberData[]>([]);
    const [removed, setRemoved] = useState<Set<string>>(new Set());
    const [adding, setAdding] = useState(false);
    const save = useForm<{ add: string[]; remove: string[] }>({ add: [], remove: [] });
    const changed = added.length > 0 || removed.size > 0;
    // Picks live on the page until Save Members: leaving asks first, as Pricing's and Inventory's boxes do.
    const leave = useLeaveGuard(changed && !save.processing, { description: plural(t, locale, 'promotions::admin_segments.pick.lost', added.length + removed.size) });
    const toggle = (row: MemberData) =>
        setRemoved((before) => {
            const next = new Set(before);
            if (next.has(row.customer.id)) next.delete(row.customer.id);
            else next.add(row.customer.id);

            return next;
        });
    const discard = () => {
        setAdded([]);
        setRemoved(new Set());
    };

    return (
        <section className="grid gap-3" aria-labelledby="members-title">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="members-title" className="text-heading-16 text-ink">
                    {t('promotions::admin_segments.show.members')}
                </h2>
                <Button type="button" variant="outline" onClick={() => setAdding(true)} data-test="add-customers">
                    {`${t('promotions::admin_segments.pick.open')}…`}
                </Button>
            </div>

            {added.length === 0 ? null : (
                <div className="grid gap-2" data-test="to-add">
                    <p className="text-copy-13 text-ink-muted">{t('promotions::admin_segments.pick.to_add', { count: figure(locale, added.length) })}</p>
                    <ul className="flex flex-wrap gap-2">
                        {added.map((row) => (
                            <li key={row.customer.id}>
                                <Badge variant="outline" className="gap-1 pe-1">
                                    <Name customer={row.customer} />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        aria-label={t('promotions::admin_segments.pick.unadd', { name: row.customer.name ?? t('promotions::admin_segments.show.deleted_customer') })}
                                        onClick={() => setAdded((before) => before.filter((other) => other.customer.id !== row.customer.id))}
                                    >
                                        <X aria-hidden="true" />
                                    </Button>
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {list.rows.length === 0 ? (
                <Empty className="material-base" data-test="members-empty">
                    <EmptyHeader>
                        <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::admin_segments.show.no_members_picked')}</EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <MembersTable rows={list.rows} removed={removed} onToggle={toggle} />
            )}
            {page.nextMember !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(`${LIST}/${page.segment.id}`, { after_member: page.nextMember ?? '' }, ['members', 'nextMember'])} /> : null}

            <div className="flex flex-wrap justify-end gap-2">
                {changed ? (
                    <Button type="button" variant="outline" onClick={discard} data-test="discard-members">
                        {t('promotions::admin_segments.pick.discard')}
                    </Button>
                ) : null}
                <ActionButton
                    type="button"
                    loading={save.processing}
                    disabledReason={changed ? undefined : t('promotions::admin_segments.pick.nothing_changed')}
                    data-test="save-members"
                    onClick={() => {
                        save.transform(() => ({ add: added.map((row) => row.customer.id), remove: [...removed] }));
                        save.post(`${LIST}/${page.segment.id}/members`, { preserveScroll: true, onSuccess: discard });
                    }}
                >
                    {t('promotions::admin_segments.pick.save')}
                </ActionButton>
            </div>

            {leave}
            <AddCustomers
                open={adding}
                onOpenChange={setAdding}
                page={page}
                added={added}
                removed={removed}
                onAdd={(row) => {
                    if (row.member) {
                        // Already in: taking a removal back is all "Add" means for a member.
                        setRemoved((before) => {
                            const next = new Set(before);
                            next.delete(row.customer.id);

                            return next;
                        });

                        return;
                    }
                    setAdded((before) => (before.some((other) => other.customer.id === row.customer.id) ? before : [...before, row]));
                }}
            />
        </section>
    );
}

/** The hand-picker's search (Q8, Q9): by name, email or phone; the server searches, at most a page shown. */
function AddCustomers({ open, onOpenChange, page, added, removed, onAdd }: { open: boolean; onOpenChange: (open: boolean) => void; page: SegmentPage; added: MemberData[]; removed: Set<string>; onAdd: (row: MemberData) => void }) {
    const t = useTranslator();
    const [text, setText] = useState(page.search ?? '');
    const [searching, setSearching] = useState(false);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const typed = text.trim();
        const timer = window.setTimeout(() => {
            router.get(`${LIST}/${page.segment.id}`, typed.length >= 2 ? { search: typed } : {}, {
                only: ['candidates', 'search', 'candidatesMore'],
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [text, page.segment.id]);

    const isIn = (row: MemberData) => (row.member && !removed.has(row.customer.id)) || added.some((other) => other.customer.id === row.customer.id);

    return (
        <PanelDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) setText('');
                onOpenChange(next);
            }}
            wide
            title={t('promotions::admin_segments.pick.title')}
            description={t('promotions::admin_segments.pick.description')}
            busy={false}
            confirm={
                <Button type="button" onClick={() => onOpenChange(false)} data-test="pick-done">
                    {t('promotions::admin_segments.pick.done')}
                </Button>
            }
        >
            <div className="grid gap-3">
                <SearchField id="search-customers" label={t('promotions::admin_segments.pick.search')} placeholder={t('promotions::admin_segments.pick.search')} value={text} onValueChange={setText} />
                {text.trim().length < 2 ? (
                    <p className="text-copy-13 text-ink-muted">{t('promotions::admin_segments.pick.type_two')}</p>
                ) : searching || page.candidates.length === 0 ? (
                    <p role="status" className="py-6 text-center text-copy-14 text-ink-muted">
                        {searching ? t('promotions::admin_segments.pick.searching') : t('promotions::admin_segments.pick.none')}
                    </p>
                ) : (
                    <div role="list" className="grid max-h-80 gap-1 overflow-y-auto" data-test="candidates">
                        {page.candidates.map((row) => {
                            const already = isIn(row);
                            const name = row.customer.name ?? t('promotions::admin_segments.show.deleted_customer');

                            return (
                                <div key={row.customer.id} role="listitem" className="flex items-center justify-between gap-3 rounded-[var(--tw-radius)] px-2 py-1.5 hover:bg-surface-sunken">
                                    <span className="truncate text-label-14 text-ink">
                                        <Name customer={row.customer} />
                                    </span>
                                    <ActionButton
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabledReason={already ? t('promotions::admin_segments.pick.already') : undefined}
                                        aria-label={t(already ? 'promotions::admin_segments.pick.added_named' : 'promotions::admin_segments.pick.add_named', { name })}
                                        onClick={() => onAdd(row)}
                                        data-test={`pick-${row.customer.id}`}
                                    >
                                        {already ? t('promotions::admin_segments.pick.added') : t('promotions::admin_segments.pick.add')}
                                    </ActionButton>
                                </div>
                            );
                        })}
                    </div>
                )}
                {page.candidatesMore ? (
                    <p role="status" className="text-copy-13 text-ink-muted" data-test="type-more">
                        {t('promotions::admin_segments.pick.more')}
                    </p>
                ) : null}
            </div>
        </PanelDialog>
    );
}
