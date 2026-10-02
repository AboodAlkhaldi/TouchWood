import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { PermissionsByRole } from '@/components/PermissionsByRole';
import { ButtonLink, EmptyState, Entity } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { RolesPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| D1 - the saved roles (frontend.md §3.4), in Geist's parts (1.10): an Entity row per role - its
| name and what it holds, and at most one control - and an Empty State when there is none.
|
| Personal roles never appear: a role made for one person is that person's business, and this list
| is about the ones an admin hands out.
|
| An admin sees admin roles here but cannot open them - `editable` is Access's answer, asked per
| role, not a guess made on this screen.
*/

type Props = RolesPage;

export default function Index({ roles, groups, permissions, permissionsByRole, mayCreate }: Props) {
    const t = useTranslator();

    return (
        <AdminLayout
            title={t('access::roles.title')}
            subtitle={t('access::roles.subtitle')}
            action={mayCreate ? <ButtonLink href="/admin/roles/new">{t('access::roles.new')}</ButtonLink> : undefined}
        >
            {roles.length === 0 ? (
                <EmptyState title={t('access::roles.none_title')} description={t('access::roles.no_roles')} />
            ) : (
                <div className="grid gap-8">
                    {/* The list Geist's EntityList draws, kept a list so a screen reader counts the
                        roles. */}
                    <ul className="material-base divide-y divide-line">
                        {roles.map((role) => (
                            /* The whole row opens the role, not just its name (owner,
                               2026-09-24). Done by stretching the one link that is already
                               there over the row, rather than wrapping the row in a second
                               one: a link inside a link is invalid, and a row announced twice
                               is worse to listen to than a row announced once. */
                            <li key={role.id} className="relative transition-colors hover:bg-surface-sunken">
                                <Entity
                                    title={
                                        <Link
                                            href={`/admin/roles/${role.id}`}
                                            className="text-ink after:absolute after:inset-0 hover:text-brand"
                                        >
                                            {role.name}
                                        </Link>
                                    }
                                    description={
                                        <>
                                            {t(`access::roles.level_${role.level.toLowerCase()}`)} ·{' '}
                                            {t('access::roles.actions_count', { count: role.permissionCount })} ·{' '}
                                            {t('access::roles.holders_count', { count: role.holderCount })}
                                        </>
                                    }
                                    actions={
                                        /* Above the stretched link, or the row would swallow it:
                                           Geist's buttons are positioned, and this one comes after
                                           the link, so it is painted over it. */
                                        role.editable ? (
                                            <ButtonLink href={`/admin/roles/${role.id}/edit`} type="secondary" size="small">
                                                {t('access::roles.edit')}
                                            </ButtonLink>
                                        ) : undefined
                                    }
                                />
                            </li>
                        ))}
                    </ul>

                    {/* The design's "Permissions by role", kept (decided 2026-09-19): areas down the
                        side, roles across the top, scrolling sideways as roles are added. */}
                    <section className="grid gap-3">
                        <div className="grid gap-1">
                            <h2 className="text-heading-16 text-ink">{t('access::roles.comparison')}</h2>
                            <p className="text-copy-13 text-ink-muted">{t('access::roles.comparison_hint')}</p>
                        </div>

                        <PermissionsByRole
                            roles={roles}
                            groups={groups}
                            permissions={permissions}
                            permissionsByRole={permissionsByRole}
                        />
                    </section>
                </div>
            )}
        </AdminLayout>
    );
}
