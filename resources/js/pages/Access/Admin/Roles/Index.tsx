import { Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { PermissionsByRole } from '@/components/PermissionsByRole';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { useTranslator } from '@/lib/t';
import type { RolesPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| D1 - the saved roles (frontend.md §3.4).
|
| Each role is a shadcn Item (Geist's Entity): its name and what it holds, **the whole row opening
| the role, and Edit Role in the row's ⋯ menu** (owner, §1.11 #7). The row is not itself one link,
| because it holds the menu's button and a control inside a link is invalid; the name's link is
| stretched over the row instead, and the ⋯ button sits above it.
|
| Personal roles never appear: a role made for one person is that person's business, and this list
| is about the ones an admin hands out.
|
| An admin sees admin roles here but cannot change them - `editable` is Access's answer, asked per
| role, not a guess made on this screen - so their rows have no menu.
*/

type Props = RolesPage;

export default function Index({ roles, groups, permissions, permissionsByRole, mayCreate }: Props) {
    const t = useTranslator();

    const create = mayCreate ? (
        <Button asChild>
            <Link href="/admin/roles/new">{t('access::roles.new')}</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title={t('access::roles.title')} subtitle={t('access::roles.subtitle')} action={create}>
            {roles.length === 0 ? (
                <Empty className="material-base">
                    <EmptyHeader>
                        <EmptyTitle>{t('access::roles.none_title')}</EmptyTitle>
                        <EmptyDescription>{t('access::roles.no_roles')}</EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="grid gap-8">
                    <ItemGroup className="material-base">
                        {roles.map((role, index) => (
                            <div key={role.id} role="listitem">
                                {index === 0 ? null : <ItemSeparator />}
                                <Item size="sm" className="relative rounded-none hover:bg-muted/50" data-test={`role-${role.id}`}>
                                    <ItemContent>
                                        <ItemTitle className="text-label-14 text-ink">
                                            <Link href={`/admin/roles/${role.id}`} className="after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:rounded-md focus-visible:after:ring-[3px] focus-visible:after:ring-ring/50">
                                                {role.name}
                                            </Link>
                                        </ItemTitle>
                                        <ItemDescription className="text-copy-13 text-ink-muted">
                                            {t(`access::roles.level_${role.level.toLowerCase()}`)} · {t('access::roles.actions_count', { count: role.permissionCount })} ·{' '}
                                            {t('access::roles.holders_count', { count: role.holderCount })}
                                        </ItemDescription>
                                    </ItemContent>

                                    {role.editable ? (
                                        <ItemActions className="relative z-10">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`${t('admin.more_actions')}: ${role.name}`}
                                                        title={t('admin.more_actions')}
                                                        data-test={`role-menu-${role.id}`}
                                                    >
                                                        <MoreHorizontal aria-hidden="true" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem asChild>
                                                        <Link href={`/admin/roles/${role.id}/edit`}>{t('access::roles.edit')}</Link>
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </ItemActions>
                                    ) : null}
                                </Item>
                            </div>
                        ))}
                    </ItemGroup>

                    {/* The design's "Permissions by role", kept (decided 2026-09-19), as a plain
                        table (§1.11 #5). */}
                    <section className="grid gap-3">
                        <div className="grid gap-1">
                            <h2 className="text-heading-16 text-ink">{t('access::roles.comparison')}</h2>
                            <p className="text-copy-13 text-ink-muted">{t('access::roles.comparison_hint')}</p>
                        </div>

                        <PermissionsByRole roles={roles} groups={groups} permissions={permissions} permissionsByRole={permissionsByRole} />
                    </section>
                </div>
            )}
        </AdminLayout>
    );
}
