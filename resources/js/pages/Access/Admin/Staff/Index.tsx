import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { SelectField } from '@/components/Fields';
import { SearchField } from '@/components/SearchField';
import { Time } from '@/components/Time';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemMedia, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { NativeSelectOption } from '@/components/ui/native-select';
import { initials } from '@/lib/initials';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { StaffListPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| C1 - the staff list (frontend.md §3.3).
|
| Grouped by the store somebody works in, with one section for those who work in more than one -
| Centralized - and admins in their own short section above the rest (decided 2026-09-19): a
| heading per group, each group a shadcn Item group (§1.11 #8).
|
| An admin seen by anybody who is not a Super Admin shows **a name and a role and nothing else**.
| That is not done here: Access leaves those fields empty, and this shows what it was given (R1).
| The list is ordered by name for such a reader, because ordering by the joining date would give an
| admin's away.
|
| In place of the design's "last seen": the status and the day they joined or were invited, through
| Geist's Relative Time Card (`Time`). Nothing is written on an ordinary page load just to fill a
| column.
|
| Each person is a shadcn Item whose whole row is the link (shadcn's `item-link`; Geist's Entity):
| initials on the start side, their status as a Badge - green for active, amber while invited, red
| otherwise - and the date on the end side. The rows hold no control of their own, so the row being
| one link nests nothing.
*/

type Props = StaffListPage;

const STATUS_TONE: Record<string, Tone> = {
    ACTIVE: 'green-subtle',
    INVITED: 'amber-subtle',
};

export default function Index({ groups, total, search, status, statuses, mayInvite }: Props) {
    const t = useTranslator();
    const [term, setTerm] = useState(search ?? '');
    const filtered = (search ?? '') !== '' || (status ?? '') !== '';

    function filter(next: { search?: string; status?: string }) {
        router.get(
            '/admin/staff',
            { search: next.search ?? term, status: next.status ?? status ?? '' },
            { preserveState: true, replace: true },
        );
    }

    function clearFilters() {
        setTerm('');
        // The button that was pressed goes with the empty state, so focus goes to the search, where
        // the person would start again (the review of batch A).
        router.get('/admin/staff', {}, { preserveState: true, replace: true, onFinish: () => document.getElementById('search')?.focus() });
    }

    const invite = mayInvite ? (
        <Button asChild>
            <Link href="/admin/staff/invite">{t('access::staff.invite')}</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title={t('access::staff.title')} subtitle={t('access::staff.subtitle')} action={invite}>
            <div className="grid gap-6">
                <div className="flex flex-wrap items-end gap-3">
                    <form
                        role="search"
                        className="w-64"
                        onSubmit={(event) => {
                            event.preventDefault();
                            filter({});
                        }}
                    >
                        <SearchField
                            id="search"
                            label={t('access::staff.search')}
                            placeholder={t('access::staff.search_placeholder')}
                            value={term}
                            onValueChange={setTerm}
                            onClear={() => filter({ search: '' })}
                        />
                    </form>

                    <SelectField
                        id="status"
                        label={t('access::staff.status')}
                        className="w-48"
                        value={status ?? ''}
                        onChange={(event) => filter({ status: event.target.value })}
                    >
                        <NativeSelectOption value="">{t('access::staff.all_statuses')}</NativeSelectOption>
                        {statuses.map((each) => (
                            <NativeSelectOption key={each} value={each}>
                                {t(`access::staff.status_${each.toLowerCase()}`)}
                            </NativeSelectOption>
                        ))}
                    </SelectField>

                    {/* The one line a screen reader hears when a filter changes what is listed (Geist's Empty
                        State: announce the new state politely) - the count, not the whole list. */}
                    <span role="status" className="tw-figure ms-auto text-label-13 text-ink-muted">
                        {t('access::staff.total', { count: total })}
                    </span>
                </div>

                <div className="grid gap-6">
                    {groups.length === 0 ? (
                        <Empty className="material-base" data-test="staff-empty">
                            <EmptyHeader>
                                <EmptyTitle>{t(filtered ? 'access::staff.no_match_title' : 'access::staff.none_title')}</EmptyTitle>
                                <EmptyDescription>
                                    {!filtered
                                        ? t('access::staff.no_staff')
                                        : (search ?? '') !== '' && (status ?? '') === ''
                                          ? t('access::staff.no_match_query', { query: search ?? '' })
                                          : t('access::staff.no_match')}
                                </EmptyDescription>
                            </EmptyHeader>
                            {filtered ? (
                                <EmptyContent>
                                    <Button variant="outline" onClick={clearFilters}>
                                        {t('access::staff.clear_filters')}
                                    </Button>
                                </EmptyContent>
                            ) : null}
                        </Empty>
                    ) : (
                        groups.map((group) => (
                            <section key={group.key} className="grid gap-2" aria-labelledby={`group-${group.key}`}>
                                <h2 id={`group-${group.key}`} className="text-heading-14 text-ink-muted">
                                    {group.label}
                                </h2>

                                <ItemGroup className="material-base">
                                    {group.staff.map((person, index) => (
                                        <Row key={person.id} first={index === 0}>
                                            <Item asChild size="sm" className="rounded-none">
                                                <Link href={`/admin/staff/${person.id}`} data-test={`staff-${person.id}`}>
                                                    <ItemMedia>
                                                        {/* Initials, not a picture: resolving one per row
                                                            would be a call to Platform per person. The
                                                            picture is on their own screen (owner,
                                                            2026-09-23). */}
                                                        <Avatar size="sm" aria-hidden="true" title={person.name}>
                                                            <AvatarFallback className="bg-brand-soft text-label-12 text-brand">{initials(person.name)}</AvatarFallback>
                                                        </Avatar>
                                                    </ItemMedia>

                                                    <ItemContent>
                                                        <ItemTitle className="text-label-14 text-ink">{person.name}</ItemTitle>
                                                        {/* A dot joins two things; with nothing before it,
                                                            it only looks like something went missing. An
                                                            admin with no role yet has neither.

                                                            The address is isolated, because an Arabic line
                                                            with a Latin address in it is reordered by the
                                                            browser otherwise (found by looking at it,
                                                            2026-09-24). */}
                                                        {person.roleName === '' && !person.email ? null : (
                                                            <ItemDescription className="text-copy-13 text-ink-muted">
                                                                {person.roleName === '' ? null : person.roleName}
                                                                {person.roleName !== '' && person.email ? ' · ' : ''}
                                                                {person.email ? <bdi dir="ltr">{person.email}</bdi> : null}
                                                            </ItemDescription>
                                                        )}
                                                    </ItemContent>

                                                    {person.status ? (
                                                        <ItemActions className="flex-wrap justify-end">
                                                            {/* A former Super Admin - shown only to Super
                                                                Admins (amendment 57) - says what they were
                                                                rather than the cancelled account revoking
                                                                left: one badge per row (Geist). */}
                                                            {person.formerSuperAdmin ? (
                                                                <Badge className={tone('gray-subtle')} data-test={`former-${person.id}`}>
                                                                    {t('access::staff.former_super_admin')}
                                                                </Badge>
                                                            ) : (
                                                                <Badge className={tone(STATUS_TONE[person.status] ?? 'red-subtle')}>
                                                                    {t(`access::staff.status_${person.status.toLowerCase()}`)}
                                                                </Badge>
                                                            )}

                                                            {person.since ? (
                                                                <span className="text-copy-13 text-ink-muted">
                                                                    <Phrase
                                                                        text={t(person.status === 'INVITED' ? 'access::staff.invited_on' : 'access::staff.since', {
                                                                            date: MARK,
                                                                        })}
                                                                        moment={<Time value={person.since} focusable={false} inSentence />}
                                                                    />
                                                                </span>
                                                            ) : null}
                                                        </ItemActions>
                                                    ) : null}
                                                </Link>
                                            </Item>
                                        </Row>
                                    ))}
                                </ItemGroup>
                            </section>
                        ))
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}

/**
 * One person in the group's list. shadcn's ItemGroup says it is a list; each row is its item, and
 * the link stays a link inside it (an Item made the link itself cannot also be the list's item).
 */
function Row({ first, children }: { first: boolean; children: ReactNode }) {
    return (
        <>
            {first ? null : <ItemSeparator />}
            <div role="listitem">{children}</div>
        </>
    );
}

/** Stands in for the moment inside a translated sentence, so each language keeps its own order. */
const MARK = '⁣';

/** A sentence with a moment inside it ("Since 2h ago"), the moment drawn by Time. */
function Phrase({ text, moment }: { text: string; moment: ReactNode }) {
    const [before, after] = text.split(MARK);

    return (
        <>
            {before}
            {moment}
            {after}
        </>
    );
}
