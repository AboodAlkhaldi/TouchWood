import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import type { StaffListPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

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
*/

type Props = StaffListPage;

export default function Index({ groups, total, search, status, statuses, mayInvite }: Props) {
    const t = useTranslator();
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
            action={
                mayInvite ? (
                    <Button asChild>
                        <Link href="/admin/staff/invite">{t('access::staff.invite')}</Link>
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-6">
                <div className="flex flex-wrap items-end gap-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            filter({});
                        }}
                        className="grid gap-1.5"
                    >
                        <label htmlFor="search" className="text-xs text-ink-muted">
                            {t('access::staff.search')}
                        </label>
                        <Input
                            id="search"
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            className="w-64"
                        />
                    </form>

                    <div className="grid gap-1.5">
                        <label htmlFor="status" className="text-xs text-ink-muted">
                            {t('access::staff.status')}
                        </label>
                        <select
                            id="status"
                            value={status ?? ''}
                            onChange={(event) => filter({ status: event.target.value })}
                            className="rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink"
                        >
                            <option value="">{t('access::staff.all_statuses')}</option>
                            {statuses.map((each) => (
                                <option key={each} value={each}>
                                    {t(`access::staff.status_${each.toLowerCase()}`)}
                                </option>
                            ))}
                        </select>
                    </div>

                    <span className="tw-figure ms-auto text-xs text-ink-muted">
                        {t('access::staff.total', { count: total })}
                    </span>
                </div>

                {groups.length === 0 ? (
                    <p className="rounded-lg border border-line bg-surface p-6 text-sm text-ink-muted">
                        {t('access::staff.no_staff')}
                    </p>
                ) : (
                    groups.map((group) => (
                        <section key={group.key} className="grid gap-2">
                            <h2 className="text-xs font-semibold tracking-wide text-ink-muted uppercase">
                                {group.label}
                            </h2>

                            <ul className="grid gap-2">
                                {group.staff.map((person) => (
                                    <li
                                        key={person.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface px-4 py-3 shadow-card"
                                    >
                                        <div className="flex items-center gap-3">
                                            {/* Initials, not a picture: resolving one per row would
                                                be a call to Platform per person. The picture is on
                                                their own screen (owner, 2026-09-23). */}
                                            <span className="grid size-9 shrink-0 place-items-center rounded-pill bg-brand-soft text-sm text-brand">
                                                {person.name.slice(0, 1)}
                                            </span>

                                            <div className="grid gap-0.5">
                                                <Link
                                                    href={`/admin/staff/${person.id}`}
                                                    className="text-sm font-medium text-ink hover:text-brand"
                                                >
                                                    {person.name}
                                                </Link>
                                                {/* A dot joins two things; with nothing before it,
                                                    it only looks like something went missing. An
                                                    admin with no role yet has neither. */}
                                                <span className="text-xs text-ink-muted">
                                                    {[person.roleName, person.email]
                                                        .filter((part) => part !== null && part !== '')
                                                        .join(' · ')}
                                                </span>
                                            </div>
                                        </div>

                                        {person.status ? (
                                            <div className="flex items-center gap-3 text-xs">
                                                <span
                                                    className={[
                                                        'rounded-pill px-2 py-0.5',
                                                        person.status === 'ACTIVE'
                                                            ? 'bg-good-soft text-good'
                                                            : person.status === 'INVITED'
                                                              ? 'bg-warn-soft text-warn'
                                                              : 'bg-bad-soft text-bad',
                                                    ].join(' ')}
                                                >
                                                    {t(`access::staff.status_${person.status.toLowerCase()}`)}
                                                </span>

                                                {person.since ? (
                                                    <span className="text-ink-muted">
                                                        {t(
                                                            person.status === 'INVITED'
                                                                ? 'access::staff.invited_on'
                                                                : 'access::staff.since',
                                                            { date: person.since.slice(0, 10) },
                                                        )}
                                                    </span>
                                                ) : null}
                                            </div>
                                        ) : null}
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
