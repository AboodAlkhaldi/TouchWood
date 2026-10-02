import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Badge, ButtonLink, EmptyState, Entity, Input, Select, type BadgeVariant } from '@/components/geist';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import type { StaffListPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';
import { useMoments } from '@/components/geist/Time';

/*
| C1 - the staff list (frontend.md §3.3).
|
| Grouped by the store somebody works in, with one section for those who work in more than one -
| Centralized - and admins in their own short section above the rest (decided 2026-09-19).
|
| An admin seen by anybody who is not a Super Admin shows **a name and a role and nothing else**.
| That is not done here: Access leaves those fields empty, and this shows what it was given (R1).
| The list is ordered by name for such a reader, because ordering by the joining date would give an
| admin's away.
|
| In place of the design's "last seen": the status and the day they joined or were invited. Nothing
| is written on an ordinary page load just to fill a column.
|
| Each person is a Geist Entity row (frontend.md 1.10): who they are on the start side, their status
| as a Badge - green for active, amber while invited, red otherwise - and the date on the end side.
*/

type Props = StaffListPage;

const STATUS_BADGE: Record<string, BadgeVariant> = {
    ACTIVE: 'green-subtle',
    INVITED: 'amber-subtle',
};

export default function Index({ groups, total, search, status, statuses, mayInvite }: Props) {
    const t = useTranslator();
    const moments = useMoments();
    const [term, setTerm] = useState(search ?? '');

    function filter(next: { search?: string; status?: string }) {
        router.get(
            '/admin/staff',
            { search: next.search ?? term, status: next.status ?? status ?? '' },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AdminLayout
            title={t('access::staff.title')}
            subtitle={t('access::staff.subtitle')}
            action={mayInvite ? <ButtonLink href="/admin/staff/invite">{t('access::staff.invite')}</ButtonLink> : undefined}
        >
            <div className="grid gap-6">
                <div className="flex flex-wrap items-end gap-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            filter({});
                        }}
                    >
                        <Input
                            id="search"
                            label={t('access::staff.search')}
                            prefix={<Search aria-hidden="true" className="size-4" />}
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            className="w-64"
                        />
                    </form>

                    <Select
                        id="status"
                        label={t('access::staff.status')}
                        value={status ?? ''}
                        onChange={(event) => filter({ status: event.target.value })}
                    >
                        <option value="">{t('access::staff.all_statuses')}</option>
                        {statuses.map((each) => (
                            <option key={each} value={each}>
                                {t(`access::staff.status_${each.toLowerCase()}`)}
                            </option>
                        ))}
                    </Select>

                    <span className="tw-figure ms-auto text-label-13 text-ink-muted">
                        {t('access::staff.total', { count: total })}
                    </span>
                </div>

                {groups.length === 0 ? (
                    <EmptyState title={t('access::staff.none_title')} description={t('access::staff.no_staff')} />
                ) : (
                    groups.map((group) => (
                        <section key={group.key} className="grid gap-2">
                            <h2 className="text-heading-14 text-ink-muted">{group.label}</h2>

                            {/* The list Geist's EntityList draws, kept a list so a screen reader
                                counts the people in it. */}
                            <ul className="material-base divide-y divide-line">
                                {group.staff.map((person) => (
                                    /* The whole row opens the person, not just their name, by
                                       stretching the link that is already there over it (owner,
                                       2026-09-24). Same as a role's row, and for the same
                                       reason: a link inside a link is invalid. */
                                    <li key={person.id} className="relative transition-colors hover:bg-surface-sunken">
                                        <Entity
                                            leading={
                                                /* Initials, not a picture: resolving one per row
                                                   would be a call to Platform per person. The
                                                   picture is on their own screen (owner,
                                                   2026-09-23). */
                                                <span
                                                    aria-hidden="true"
                                                    className="grid size-8 place-items-center rounded-full bg-brand-soft text-label-14 text-brand"
                                                >
                                                    {person.name.slice(0, 1)}
                                                </span>
                                            }
                                            title={
                                                <Link
                                                    href={`/admin/staff/${person.id}`}
                                                    className="text-ink after:absolute after:inset-0 hover:text-brand"
                                                >
                                                    {person.name}
                                                </Link>
                                            }
                                            description={
                                                /* A dot joins two things; with nothing before it,
                                                   it only looks like something went missing. An
                                                   admin with no role yet has neither.

                                                   The address is isolated, because an Arabic line
                                                   with a Latin address in it is reordered by the
                                                   browser otherwise: the pieces stay put but the
                                                   line reads inside out (found by looking at it,
                                                   2026-09-24). */
                                                person.roleName === '' && !person.email ? undefined : (
                                                    <>
                                                        {person.roleName === '' ? null : person.roleName}
                                                        {person.roleName !== '' && person.email ? ' · ' : ''}
                                                        {person.email ? <bdi dir="ltr">{person.email}</bdi> : null}
                                                    </>
                                                )
                                            }
                                            actions={
                                                person.status ? (
                                                    <>
                                                        <Badge variant={STATUS_BADGE[person.status] ?? 'red-subtle'}>
                                                            {t(`access::staff.status_${person.status.toLowerCase()}`)}
                                                        </Badge>

                                                        {/* Isolated, or an Arabic line turns
                                                            2026-09-24 around and shows 24-09-2026
                                                            (see lib/bidi). */}
                                                        {person.since ? (
                                                            <span className="text-copy-13 text-ink-muted">
                                                                {t(
                                                                    person.status === 'INVITED'
                                                                        ? 'access::staff.invited_on'
                                                                        : 'access::staff.since',
                                                                    { date: isolate(moments.date(person.since)) },
                                                                )}
                                                            </span>
                                                        ) : null}
                                                    </>
                                                ) : undefined
                                            }
                                        />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))
                )}
            </div>
        </AdminLayout>
    );
}
