import { useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { PermissionPicker } from '@/components/PermissionPicker';
import { ALL_STORES, ExceptionList, SELECTED_STORES, StoreChoice } from '@/components/RoleStores';
import type { ExceptionRow } from '@/components/RoleStores';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import type { StaffRolePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| C6 - one person's role and stores (frontend.md §3.3).
|
| Two questions, in this order. **What** may they do: a saved role, taken as it is, or one edited
| into a role of their own - editing here never changes the saved role for the other people holding
| it (access.md §1.5). **Where**: every store, or the ones ticked; and an action may be given stores
| of its own when it should reach further, or less far, than the rest of the role.
|
| Only the stores this admin manages themselves are offered, because nobody hands out reach they do
| not have. Actions they do not hold are shown but cannot be ticked, which is the picker's doing.
*/

type Props = StaffRolePage;

export default function Role(page: Props) {
    const t = useTranslator();

    // Their personal role is not one of the saved ones, so nothing is picked from the list for it.
    const [roleId, setRoleId] = useState<string | null>(page.personal ? null : page.roleId);
    const [chosen, setChosen] = useState<string[]>(page.chosen);

    const form = useForm({
        access_level: page.accessLevel,
        store_ids: page.storeIds,
        exceptions: Object.entries(page.exceptions).map(
            ([permission, storeIds]): ExceptionRow => ({
                permission,
                access_level: storeIds.length === 0 ? ALL_STORES : SELECTED_STORES,
                store_ids: storeIds,
            }),
        ),
    });

    // A saved role picked and left alone is given as it is; touch one action and it becomes a role
    // of this person's own instead. That is the whole difference, so it is worked out rather than
    // remembered in a flag that could drift.
    const edited = useMemo(() => {
        if (roleId === null) {
            return true;
        }

        const saved = page.savedPermissions[roleId] ?? [];

        return saved.length !== chosen.length || saved.some((name) => !chosen.includes(name));
    }, [roleId, chosen, page.savedPermissions]);

    function pick(id: string | null) {
        setRoleId(id);
        setChosen(id === null ? [] : (page.savedPermissions[id] ?? []));
    }

    function submit() {
        // transform() only records how to shape the data; the post right after it is what sends.
        form.transform((data) => ({
            ...data,
            saved_role_id: edited ? '' : roleId,
            permissions: edited ? chosen : [],
            // An action that is no longer in the role cannot keep stores of its own.
            exceptions: data.exceptions.filter((row) => chosen.includes(row.permission)),
        }));

        form.post(`/admin/staff/${page.staffId}/role`);
    }

    return (
        <AdminLayout
            title={t('access::staff.change_role')}
            subtitle={page.staffName}
            action={
                <Button variant="ghost" asChild>
                    <Link href={`/admin/staff/${page.staffId}`}>
                        {t('access::staff.back_to', { name: page.staffName })}
                    </Link>
                </Button>
            }
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="grid gap-6"
            >
                <FormError />

                <section className="grid gap-3">
                    <div className="grid gap-0.5">
                        <h2 className="text-sm font-semibold text-ink">{t('access::staff.pick_role')}</h2>
                        <p className="text-xs text-ink-muted">{t('access::staff.pick_role_hint')}</p>
                    </div>

                    <ul className="grid gap-2 sm:grid-cols-2">
                        {page.savedRoles.map((role) => (
                            <li key={role.id}>
                                <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-line bg-surface px-4 py-3">
                                    <input
                                        type="radio"
                                        name="role"
                                        className="mt-1 accent-brand"
                                        checked={roleId === role.id}
                                        onChange={() => pick(role.id)}
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="text-sm text-ink">
                                            {role.name}
                                            {roleId === role.id && edited ? (
                                                <span className="ms-2 rounded-pill bg-warn-soft px-2 py-0.5 text-xs text-warn">
                                                    {t('access::staff.edited')}
                                                </span>
                                            ) : null}
                                        </span>
                                        <span className="tw-figure text-xs text-ink-muted">
                                            {t('access::staff.actions_count', { count: role.permissionCount })}
                                        </span>
                                    </span>
                                </label>
                            </li>
                        ))}

                        <li>
                            <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-line bg-surface px-4 py-3">
                                <input
                                    type="radio"
                                    name="role"
                                    className="mt-1 accent-brand"
                                    checked={roleId === null}
                                    onChange={() => pick(null)}
                                />
                                <span className="grid gap-0.5">
                                    <span className="text-sm text-ink">{t('access::staff.own_role')}</span>
                                    <span className="text-xs text-ink-muted">{t('access::staff.own_role_hint')}</span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </section>

                <PermissionPicker
                    permissions={page.permissions}
                    groups={page.groups}
                    chosen={chosen}
                    onChange={setChosen}
                />

                <section className="grid gap-3">
                    <div className="grid gap-0.5">
                        <h2 className="text-sm font-semibold text-ink">{t('access::staff.where')}</h2>
                        <p className="text-xs text-ink-muted">{t('access::staff.where_hint')}</p>
                    </div>

                    <StoreChoice
                        stores={page.stores}
                        level={form.data.access_level}
                        chosen={form.data.store_ids}
                        onLevel={(level) => form.setData('access_level', level)}
                        onChosen={(ids) => form.setData('store_ids', ids)}
                    />
                </section>

                <section className="grid gap-3">
                    <div className="grid gap-0.5">
                        <h2 className="text-sm font-semibold text-ink">{t('access::staff.exceptions_title')}</h2>
                        <p className="text-xs text-ink-muted">{t('access::staff.exceptions_hint')}</p>
                    </div>

                    <ExceptionList
                        permissions={page.permissions}
                        chosen={chosen}
                        stores={page.stores}
                        rows={form.data.exceptions}
                        onChange={(rows) => form.setData('exceptions', rows)}
                    />
                </section>

                <div>
                    <Button type="submit" disabled={form.processing}>
                        {t('access::staff.save_role')}
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
