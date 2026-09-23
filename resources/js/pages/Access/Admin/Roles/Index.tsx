import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import type { RolesPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| D1 - the saved roles (frontend.md §3.4).
|
| Personal roles never appear: a role made for one person is that person's business, and this list
| is about the ones an admin hands out.
|
| An admin sees admin roles here but cannot open them - `editable` is Access's answer, asked per
| role, not a guess made on this screen.
*/

type Props = RolesPage;

export default function Index({ roles, groups, mayCreate }: Props) {
    const t = useTranslator();

    return (
        <AdminLayout
            title={t('access::roles.title')}
            subtitle={t('access::roles.subtitle')}
            action={
                mayCreate ? (
                    <Button asChild>
                        <Link href="/admin/roles/new">{t('access::roles.new')}</Link>
                    </Button>
                ) : undefined
            }
        >
            {roles.length === 0 ? (
                <p className="rounded-lg border border-line bg-surface p-6 text-sm text-ink-muted">
                    {t('access::roles.no_roles')}
                </p>
            ) : (
                <div className="grid gap-8">
                    <ul className="grid gap-2">
                        {roles.map((role) => (
                            <li
                                key={role.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface px-4 py-3 shadow-card"
                            >
                                <div className="grid gap-0.5">
                                    <Link
                                        href={`/admin/roles/${role.id}`}
                                        className="text-sm font-medium text-ink hover:text-brand"
                                    >
                                        {role.name}
                                    </Link>
                                    <span className="text-xs text-ink-muted">
                                        {t(`access::roles.level_${role.level.toLowerCase()}`)} ·{' '}
                                        {t('access::roles.actions_count', { count: role.permissionCount })} ·{' '}
                                        {t('access::roles.holders_count', { count: role.holderCount })}
                                    </span>
                                </div>

                                {role.editable ? (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={`/admin/roles/${role.id}/edit`}>{t('access::roles.edit')}</Link>
                                    </Button>
                                ) : null}
                            </li>
                        ))}
                    </ul>

                    {/* The design's "Permissions by role", kept (decided 2026-09-19): areas down the
                        side, roles across the top, scrolling sideways as roles are added. */}
                    <section className="grid gap-2">
                        <div className="grid gap-0.5">
                            <h2 className="text-sm font-semibold text-ink">{t('access::roles.comparison')}</h2>
                            <p className="text-xs text-ink-muted">{t('access::roles.comparison_hint')}</p>
                        </div>

                        <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                            <table className="w-full min-w-max text-sm">
                                <thead>
                                    <tr className="border-b border-line">
                                        <th className="px-4 py-3 text-start text-xs font-semibold text-ink-muted uppercase">
                                            {t('access::roles.comparison')}
                                        </th>
                                        {roles.map((role) => (
                                            <th
                                                key={role.id}
                                                className="px-4 py-3 text-start text-xs font-semibold text-ink-muted"
                                            >
                                                {role.name}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {groups.map((group) => (
                                        <tr key={group.key} className="border-b border-line last:border-0">
                                            <th className="px-4 py-2 text-start font-normal text-ink">{group.label}</th>
                                            {roles.map((role) => (
                                                <td key={role.id} className="px-4 py-2 text-ink-muted">
                                                    {role.groups.includes(group.key) ? (
                                                        <span className="text-good" aria-label={group.label}>
                                                            ●
                                                        </span>
                                                    ) : (
                                                        <span className="text-ink-subtle">–</span>
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            )}
        </AdminLayout>
    );
}
