import { useEffect, useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { PermissionPicker } from '@/components/PermissionPicker';
import { ALL_STORES, ExceptionList, SELECTED_STORES, StoreChoice } from '@/components/RoleStores';
import type { ExceptionRow } from '@/components/RoleStores';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import type { InviteStaffPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| C3 - inviting a staff member (frontend.md §3.3).
|
| Three steps with a progress line: who they are, what they may do, and where. **Nothing is sent
| until the last step** [decided 2026-09-19] - leaving halfway writes nothing and creates nobody,
| so there is no half-made account for an admin to find later and wonder about.
|
| A refusal that belongs to an earlier step takes the person back to it, rather than showing a
| message next to a field they cannot see.
*/

type Props = InviteStaffPage;

const PROFILE_FIELDS = [
    'email',
    'first_name',
    'last_name',
    'job_title',
    'date_of_birth',
    'country',
    'address',
    'phone',
    'locale',
];

const ROLE_FIELDS = ['saved_role_id', 'permissions'];

export default function Invite(page: Props) {
    const t = useTranslator();
    const [step, setStep] = useState(1);
    const [admin, setAdmin] = useState(false);
    const [roleId, setRoleId] = useState<string | null>(null);
    const [chosen, setChosen] = useState<string[]>([]);

    const form = useForm({
        email: '',
        first_name: '',
        last_name: '',
        job_title: '',
        date_of_birth: '',
        country: page.countries[0]?.code ?? '',
        address: '',
        phone: '',
        locale: page.locale,
        access_level: SELECTED_STORES,
        store_ids: [] as string[],
        exceptions: [] as ExceptionRow[],
    });

    // An admin is not tied to a store, and only a Super Admin may bring one in.
    const permissions = admin ? page.adminPermissions : page.permissions;
    const level = admin ? 'ADMIN' : 'STAFF';
    const roles = useMemo(
        () => page.savedRoles.filter((role) => role.level === level),
        [page.savedRoles, level],
    );

    const edited = useMemo(() => {
        if (roleId === null) {
            return true;
        }

        const saved = page.savedPermissions[roleId] ?? [];

        return saved.length !== chosen.length || saved.some((name) => !chosen.includes(name));
    }, [roleId, chosen, page.savedPermissions]);

    // The whole form is sent at once, so a refusal about the profile arrives while the person is
    // looking at the stores. Take them back to where the answer belongs.
    useEffect(() => {
        const wrong = Object.keys(form.errors);

        if (wrong.length === 0) {
            return;
        }

        setStep(wrong.some((field) => PROFILE_FIELDS.includes(field)) ? 1 : wrong.some((field) => ROLE_FIELDS.includes(field)) ? 2 : 3);
    }, [form.errors]);

    function pick(id: string | null) {
        setRoleId(id);
        setChosen(id === null ? [] : (page.savedPermissions[id] ?? []));
    }

    function send() {
        // transform() only records how to shape the data; the post right after it is what sends.
        form.transform((data) => ({
            ...data,
            admin,
            saved_role_id: edited ? '' : roleId,
            permissions: edited ? chosen : [],
            exceptions: data.exceptions.filter((row) => chosen.includes(row.permission)),
        }));

        form.post('/admin/staff/invite');
    }

    const steps = [
        t('access::staff.step_profile'),
        t('access::staff.step_role'),
        t('access::staff.step_stores'),
    ];

    return (
        <AdminLayout
            title={t('access::staff.invite_title')}
            subtitle={t('access::staff.invite_subtitle')}
            breadcrumbs={[{ label: t('access::staff.title'), href: '/admin/staff' }]}
            action={
                <Button variant="ghost" asChild>
                    <Link href="/admin/staff">{t('access::staff.cancel')}</Link>
                </Button>
            }
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();

                    if (step < 3) {
                        setStep(step + 1);

                        return;
                    }

                    send();
                }}
                className="grid max-w-3xl gap-6"
            >
                <ol className="flex flex-wrap items-center gap-3 text-sm">
                    {steps.map((label, index) => {
                        const number = index + 1;

                        return (
                            <li key={label} className="flex items-center gap-2">
                                <span
                                    className={[
                                        'tw-figure grid size-6 place-items-center rounded-pill text-xs',
                                        number === step
                                            ? 'bg-brand text-brand-ink'
                                            : number < step
                                              ? 'bg-brand-soft text-brand'
                                              : 'bg-surface-sunken text-ink-muted',
                                    ].join(' ')}
                                >
                                    {number}
                                </span>
                                <span className={number === step ? 'text-ink' : 'text-ink-muted'}>{label}</span>
                                {number < steps.length ? <span className="text-ink-muted">·</span> : null}
                            </li>
                        );
                    })}

                    <li className="tw-figure ms-auto text-xs text-ink-muted">
                        {t('access::staff.step_of', { step, total: steps.length })}
                    </li>
                </ol>

                <FormError />

                {step === 1 ? (
                    <section className="grid gap-4 rounded-lg border border-line bg-surface p-4 sm:grid-cols-2">
                        <Field id="first_name" label={t('access::staff.first_name')} error={form.errors.first_name}>
                            <Input
                                id="first_name"
                                required
                                value={form.data.first_name}
                                onChange={(event) => form.setData('first_name', event.target.value)}
                            />
                        </Field>

                        <Field id="last_name" label={t('access::staff.last_name')} error={form.errors.last_name}>
                            <Input
                                id="last_name"
                                required
                                value={form.data.last_name}
                                onChange={(event) => form.setData('last_name', event.target.value)}
                            />
                        </Field>

                        <Field id="email" label={t('access::staff.email')} error={form.errors.email}>
                            <Input
                                id="email"
                                type="email"
                                dir="ltr"
                                required
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                        </Field>

                        <Field id="phone" label={t('access::staff.phone')} error={form.errors.phone}>
                            <Input
                                id="phone"
                                dir="ltr"
                                className="tw-figure"
                                required
                                value={form.data.phone}
                                onChange={(event) => form.setData('phone', event.target.value)}
                            />
                        </Field>

                        <Field id="job_title" label={t('access::staff.job_title')} error={form.errors.job_title}>
                            <Input
                                id="job_title"
                                required
                                value={form.data.job_title}
                                onChange={(event) => form.setData('job_title', event.target.value)}
                            />
                        </Field>

                        <Field
                            id="date_of_birth"
                            label={t('access::staff.date_of_birth')}
                            error={form.errors.date_of_birth}
                        >
                            <Input
                                id="date_of_birth"
                                type="date"
                                dir="ltr"
                                required
                                value={form.data.date_of_birth}
                                onChange={(event) => form.setData('date_of_birth', event.target.value)}
                            />
                        </Field>

                        <Field id="country" label={t('access::staff.country')} error={form.errors.country}>
                            <select
                                id="country"
                                required
                                value={form.data.country}
                                onChange={(event) => form.setData('country', event.target.value)}
                                className="h-9 w-full rounded-md border border-line-strong bg-surface px-3 text-sm text-ink"
                            >
                                {page.countries.map((country) => (
                                    <option key={country.code} value={country.code}>
                                        {country.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field
                            id="locale"
                            label={t('access::staff.communication_language')}
                            hint={t('access::staff.communication_language_hint')}
                            error={form.errors.locale}
                        >
                            <select
                                id="locale"
                                value={form.data.locale}
                                onChange={(event) => form.setData('locale', event.target.value)}
                                className="h-9 w-full rounded-md border border-line-strong bg-surface px-3 text-sm text-ink"
                            >
                                <option value="ar">العربية</option>
                                <option value="en">English</option>
                            </select>
                        </Field>

                        <div className="sm:col-span-2">
                            <Field id="address" label={t('access::staff.address')} error={form.errors.address}>
                                <Input
                                    id="address"
                                    value={form.data.address}
                                    onChange={(event) => form.setData('address', event.target.value)}
                                />
                            </Field>
                        </div>
                    </section>
                ) : null}

                {step === 2 ? (
                    <div className="grid gap-6">
                        {page.maySetAdmin ? (
                            <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-line bg-surface px-4 py-3">
                                <Checkbox
                                    checked={admin}
                                    onCheckedChange={(on) => {
                                        setAdmin(on === true);
                                        pick(null);
                                    }}
                                />
                                <span className="grid gap-0.5">
                                    <span className="text-sm text-ink">{t('access::staff.as_admin')}</span>
                                    <span className="text-xs text-ink-muted">{t('access::staff.as_admin_hint')}</span>
                                </span>
                            </label>
                        ) : null}

                        <section className="grid gap-3">
                            <div className="grid gap-0.5">
                                <h2 className="text-sm font-semibold text-ink">{t('access::staff.pick_role')}</h2>
                                <p className="text-xs text-ink-muted">{t('access::staff.pick_role_hint')}</p>
                            </div>

                            <ul className="grid gap-2 sm:grid-cols-2">
                                {roles.map((role) => (
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
                                                    {t('access::staff.actions_count', {
                                                        count: role.permissionCount,
                                                    })}
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
                                            <span className="text-xs text-ink-muted">
                                                {t('access::staff.own_role_hint')}
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            </ul>
                        </section>

                        <PermissionPicker
                            permissions={permissions}
                            groups={page.groups}
                            chosen={chosen}
                            onChange={setChosen}
                        />
                    </div>
                ) : null}

                {step === 3 ? (
                    <div className="grid gap-6">
                        <section className="grid gap-3">
                            <div className="grid gap-0.5">
                                <h2 className="text-sm font-semibold text-ink">{t('access::staff.where')}</h2>
                                <p className="text-xs text-ink-muted">{t('access::staff.where_hint')}</p>
                            </div>

                            <StoreChoice
                                stores={page.stores}
                                level={form.data.access_level}
                                chosen={form.data.store_ids}
                                onLevel={(next) => form.setData('access_level', next)}
                                onChosen={(ids) => form.setData('store_ids', ids)}
                            />
                        </section>

                        <section className="grid gap-3">
                            <div className="grid gap-0.5">
                                <h2 className="text-sm font-semibold text-ink">
                                    {t('access::staff.exceptions_title')}
                                </h2>
                                <p className="text-xs text-ink-muted">{t('access::staff.exceptions_hint')}</p>
                            </div>

                            <ExceptionList
                                permissions={permissions}
                                chosen={chosen}
                                stores={page.stores}
                                rows={form.data.exceptions}
                                onChange={(rows) => form.setData('exceptions', rows)}
                            />
                        </section>
                    </div>
                ) : null}

                <div className="flex flex-wrap items-center gap-3">
                    {step > 1 ? (
                        <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>
                            {t('access::staff.back')}
                        </Button>
                    ) : null}

                    <Button type="submit" disabled={form.processing}>
                        {t(step < 3 ? 'access::staff.next' : 'access::staff.send_invitation')}
                    </Button>

                    <span className="text-xs text-ink-muted">{t('access::staff.nothing_sent_yet')}</span>
                </div>
            </form>
        </AdminLayout>
    );
}
