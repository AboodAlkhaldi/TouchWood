import { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PermissionPicker } from '@/components/PermissionPicker';
import { RoleChoice } from '@/components/RoleChoice';
import {
    ActionStores,
    emptyCustom,
    reachAllowsCustom,
    reachStores,
    reachToSend,
    SELECTED_STORES,
    setActionStores,
    WhereItReaches,
    withinReach,
} from '@/components/RoleStores';
import type { ExceptionRow } from '@/components/RoleStores';
import { useList } from '@/lib/list';
import { useTranslator } from '@/lib/t';
import type { StaffRolePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| C6 - one person's role and stores (frontend.md §3.3, access.md amendment 59).
|
| Top to bottom, each in its own box: the **role** - a saved role, taken as it is, or one edited into
| a role of their own; editing here never changes the saved role for the other people holding it
| (access.md §1.5) -, its **actions** by business area, **Where It Reaches**, and then **each
| action's stores**, only when the reach has two or more stores or every store.
|
| Where It Reaches bounds every action (the owner's rule, 2026-10-04): a smaller reach cuts every
| custom choice to it and says so on the action before saving, and Save stops, naming the action,
| when a custom choice is left with nothing (D9) - never widening it behind anyone's back. The cut
| is worked out from the choices as made, so ticking a store back gives it back.
|
| Only the stores this admin manages themselves are offered, because nobody hands out reach they do
| not have. Actions they do not hold are shown but cannot be ticked, which is the picker's doing.
*/

type Props = StaffRolePage;

export default function Role(page: Props) {
    const t = useTranslator();
    const list = useList();

    // Their personal role is not one of the saved ones, so nothing is picked from the list for it.
    const [roleId, setRoleId] = useState<string | null>(page.personal ? null : page.roleId);
    const [chosen, setChosen] = useState<string[]>(page.chosen);
    // The actions the last Save stopped on.
    const [stoppedOn, setStoppedOn] = useState<string[]>([]);

    const form = useForm({
        access_level: page.accessLevel,
        store_ids: page.storeIds,
        // An action's own stores are always some of the reach's (amendment 59); one stored as "every
        // store" could only equal an every-store reach, so it says nothing and is left out.
        exceptions: Object.entries(page.exceptions)
            .filter(([, storeIds]) => storeIds.length > 0)
            .map(([permission, storeIds]): ExceptionRow => ({ permission, access_level: SELECTED_STORES, store_ids: storeIds })),
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

    const allowsCustom = reachAllowsCustom(form.data.access_level, form.data.store_ids);
    const within = withinReach(form.data.exceptions, form.data.access_level, form.data.store_ids);
    const empty = emptyCustom(within.rows, chosen);
    const stillStopped = empty.filter((permission) => stoppedOn.includes(permission));
    const labelOf = (name: string): string => page.permissions.find((permission) => permission.name === name)?.label ?? name;

    function pick(id: string | null) {
        setRoleId(id);
        setChosen(id === null ? [] : (page.savedPermissions[id] ?? []));
    }

    function submit() {
        if (empty.length > 0) {
            setStoppedOn(empty);

            return;
        }

        // transform() only records how to shape the data; the post right after it is what sends.
        form.transform((data) => ({
            ...data,
            saved_role_id: edited ? '' : roleId,
            permissions: edited ? chosen : [],
            store_ids: reachToSend(data.access_level, data.store_ids),
            // An action that is no longer in the role cannot keep stores of its own, and with one
            // store in the reach there is nothing to choose.
            exceptions: allowsCustom ? within.rows.filter((row) => chosen.includes(row.permission)) : [],
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
                {stillStopped.length > 0 ? (
                    <Note variant="error" alert data-test="exceptions-empty-refusal">
                        {t('access::staff.exceptions_empty', { actions: list(stillStopped.map(labelOf)) })}
                    </Note>
                ) : null}

                <RoleChoice roles={page.savedRoles} value={roleId} edited={edited} onPick={pick} />

                <PermissionPicker permissions={page.permissions} groups={page.groups} chosen={chosen} onChange={setChosen} />

                <WhereItReaches
                    stores={page.stores}
                    level={form.data.access_level}
                    chosen={form.data.store_ids}
                    onLevel={(level) => form.setData('access_level', level)}
                    onChosen={(ids) => form.setData('store_ids', ids)}
                />

                {/* Also shown with one store while a choice there is left with nothing, so it can be put right. */}
                {allowsCustom || empty.length > 0 ? (
                    <ActionStores
                        permissions={page.permissions}
                        groups={page.groups}
                        chosen={chosen}
                        stores={reachStores(page.stores, form.data.access_level, form.data.store_ids)}
                        offered={page.stores}
                        rows={within.rows}
                        onSet={(permission, storeIds) => form.setData('exceptions', setActionStores(form.data.exceptions, permission, storeIds))}
                        lost={within.lost}
                        empty={stoppedOn}
                        only={allowsCustom ? undefined : empty}
                    />
                ) : null}

                <div>
                    <ActionButton type="submit" loading={form.processing}>
                        {t('access::staff.save_role')}
                    </ActionButton>
                </div>
            </form>
        </AdminLayout>
    );
}
