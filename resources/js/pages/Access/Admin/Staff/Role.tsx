import { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PermissionPicker } from '@/components/PermissionPicker';
import { RoleChoice } from '@/components/RoleChoice';
import { ALL_STORES, ExceptionList, SELECTED_STORES, StoreChoice } from '@/components/RoleStores';
import type { ExceptionRow } from '@/components/RoleStores';
import { FieldDescription, FieldLegend, FieldSet } from '@/components/ui/field';
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
| shadcn's parts (frontend.md §1.11): the role is shadcn's choice cards, as on the invitation's
| second step; "where" and the exceptions are FieldSets whose legend and description are the
| section's heading and hint, so each group of controls is named by them.
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

                <RoleChoice roles={page.savedRoles} value={roleId} edited={edited} onPick={pick} />

                <PermissionPicker permissions={page.permissions} groups={page.groups} chosen={chosen} onChange={setChosen} />

                <FieldSet className="gap-3">
                    <FieldLegend id="where" className="mb-0 text-heading-16 text-ink">
                        {t('access::staff.where')}
                    </FieldLegend>
                    <FieldDescription className="text-copy-13 text-ink-muted">{t('access::staff.where_hint')}</FieldDescription>
                    <StoreChoice
                        labelledBy="where"
                        stores={page.stores}
                        level={form.data.access_level}
                        chosen={form.data.store_ids}
                        onLevel={(level) => form.setData('access_level', level)}
                        onChosen={(ids) => form.setData('store_ids', ids)}
                    />
                </FieldSet>

                <FieldSet className="gap-3">
                    <FieldLegend className="mb-0 text-heading-16 text-ink">{t('access::staff.exceptions_title')}</FieldLegend>
                    <FieldDescription className="text-copy-13 text-ink-muted">{t('access::staff.exceptions_hint')}</FieldDescription>
                    <ExceptionList
                        permissions={page.permissions}
                        chosen={chosen}
                        stores={page.stores}
                        rows={form.data.exceptions}
                        onChange={(rows) => form.setData('exceptions', rows)}
                    />
                </FieldSet>

                <div>
                    <ActionButton type="submit" loading={form.processing}>
                        {t('access::staff.save_role')}
                    </ActionButton>
                </div>
            </form>
        </AdminLayout>
    );
}
