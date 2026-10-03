import { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { PermissionPicker } from '@/components/PermissionPicker';
import { ALL_STORES, ExceptionList, SELECTED_STORES, StoreChoice } from '@/components/RoleStores';
import type { ExceptionRow } from '@/components/RoleStores';
import { Badge, Button, RadioGroup } from '@/components/geist';
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
|
| In Geist's choices (frontend.md 1.10): the role is a RadioGroup - one saved role, or a role of
| their own - as on the invitation's second step.
*/

type Props = StaffRolePage;

/** The radio value for "a role of their own", which is no saved role at all. */
const OWN_ROLE = 'own';

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
            // The way back, rather than a button saying the same thing twice.
            breadcrumbs={[
                { label: t('access::staff.title'), href: '/admin/staff' },
                { label: page.staffName, href: `/admin/staff/${page.staffId}` },
            ]}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="grid gap-6"
            >
                <FormError />

                <section className="material-base p-5">
                    <RadioGroup
                        name="role"
                        legend={
                            <span className="grid gap-1">
                                <span className="text-heading-16 text-ink">{t('access::staff.pick_role')}</span>
                                <span className="text-copy-13 font-normal text-ink-muted">{t('access::staff.pick_role_hint')}</span>
                            </span>
                        }
                        value={roleId ?? OWN_ROLE}
                        onChange={(value) => pick(value === OWN_ROLE ? null : value)}
                        options={[
                            ...page.savedRoles.map((role) => ({
                                value: role.id,
                                label: (
                                    <span className="grid gap-0.5">
                                        <span className="flex flex-wrap items-center gap-2">
                                            {role.name}
                                            {roleId === role.id && edited ? (
                                                <Badge variant="amber-subtle" size="small">
                                                    {t('access::staff.edited')}
                                                </Badge>
                                            ) : null}
                                        </span>
                                        <span className="tw-figure text-copy-13 text-ink-muted">
                                            {t('access::staff.actions_count', { count: role.permissionCount })}
                                        </span>
                                    </span>
                                ),
                            })),
                            {
                                value: OWN_ROLE,
                                label: (
                                    <span className="grid gap-0.5">
                                        <span>{t('access::staff.own_role')}</span>
                                        <span className="text-copy-13 text-ink-muted">{t('access::staff.own_role_hint')}</span>
                                    </span>
                                ),
                            },
                        ]}
                    />
                </section>

                <PermissionPicker
                    permissions={page.permissions}
                    groups={page.groups}
                    chosen={chosen}
                    onChange={setChosen}
                />

                <section className="grid gap-3">
                    <div className="grid gap-1">
                        <h2 className="text-heading-16 text-ink">{t('access::staff.where')}</h2>
                        <p className="text-copy-13 text-ink-muted">{t('access::staff.where_hint')}</p>
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
                    <div className="grid gap-1">
                        <h2 className="text-heading-16 text-ink">{t('access::staff.exceptions_title')}</h2>
                        <p className="text-copy-13 text-ink-muted">{t('access::staff.exceptions_hint')}</p>
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
                    <Button typeName="submit" loading={form.processing}>
                        {t('access::staff.save_role')}
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
