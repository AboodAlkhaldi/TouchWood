import { useEffect, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { PermissionPicker } from '@/components/PermissionPicker';
import { ExceptionList, SELECTED_STORES, StoreChoice } from '@/components/RoleStores';
import type { ExceptionRow } from '@/components/RoleStores';
import { Badge, Button, ButtonLink, Checkbox, Input, RadioGroup, Select } from '@/components/geist';
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
|
| In Geist's fields and choices (frontend.md 1.10). Geist has no stepper, so the progress line is
| built from its type and colours. The role is one choice of several, so a RadioGroup - a saved
| role, or a role of their own. The button that moves on stays a submit, so Enter on any field
| goes to the next step exactly as the button does.
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

/** The radio value for "a role of their own", which is no saved role at all. */
const OWN_ROLE = 'own';

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
        locale: page.communicationLocale,
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
                <ButtonLink href="/admin/staff" type="tertiary">
                    {t('access::staff.cancel')}
                </ButtonLink>
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
                <ol className="flex flex-wrap items-center gap-3 text-label-14">
                    {steps.map((label, index) => {
                        const number = index + 1;

                        return (
                            <li
                                key={label}
                                aria-current={number === step ? 'step' : undefined}
                                className="flex items-center gap-2"
                            >
                                <span
                                    className={[
                                        'tw-figure grid size-6 place-items-center rounded-full text-label-12',
                                        number === step
                                            ? 'bg-brand text-ink-on-brand'
                                            : number < step
                                              ? 'bg-brand-soft text-brand'
                                              : 'bg-surface-sunken text-ink-muted',
                                    ].join(' ')}
                                >
                                    {number}
                                </span>
                                <span className={number === step ? 'text-ink' : 'text-ink-muted'}>{label}</span>
                                {number < steps.length ? <span className="text-ink-subtle">·</span> : null}
                            </li>
                        );
                    })}

                    <li className="tw-figure ms-auto text-label-13 text-ink-muted">
                        {t('access::staff.step_of', { step, total: steps.length })}
                    </li>
                </ol>

                <FormError />

                {step === 1 ? (
                    <section className="material-base grid gap-4 p-5 sm:grid-cols-2">
                        <Input
                            id="first_name"
                            label={t('access::staff.first_name')}
                            error={form.errors.first_name}
                            required
                            value={form.data.first_name}
                            onChange={(event) => form.setData('first_name', event.target.value)}
                        />

                        <Input
                            id="last_name"
                            label={t('access::staff.last_name')}
                            error={form.errors.last_name}
                            required
                            value={form.data.last_name}
                            onChange={(event) => form.setData('last_name', event.target.value)}
                        />

                        <Input
                            id="email"
                            type="email"
                            label={t('access::staff.email')}
                            error={form.errors.email}
                            dir="ltr"
                            required
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />

                        <Input
                            id="phone"
                            label={t('access::staff.phone')}
                            error={form.errors.phone}
                            dir="ltr"
                            className="tw-figure"
                            required
                            value={form.data.phone}
                            onChange={(event) => form.setData('phone', event.target.value)}
                        />

                        <Input
                            id="job_title"
                            label={t('access::staff.job_title')}
                            error={form.errors.job_title}
                            required
                            value={form.data.job_title}
                            onChange={(event) => form.setData('job_title', event.target.value)}
                        />

                        <Input
                            id="date_of_birth"
                            type="date"
                            label={t('access::staff.date_of_birth')}
                            error={form.errors.date_of_birth}
                            dir="ltr"
                            required
                            value={form.data.date_of_birth}
                            onChange={(event) => form.setData('date_of_birth', event.target.value)}
                        />

                        <Select
                            id="country"
                            label={t('access::staff.country')}
                            error={form.errors.country}
                            required
                            value={form.data.country}
                            onChange={(event) => form.setData('country', event.target.value)}
                        >
                            {/* The countries our stores are in come first, under their own
                                heading; they appear in the long list too, so somebody looking
                                for Saudi Arabia under S still finds it (owner, 2026-09-24). */}
                            <optgroup label={t('access::staff.countries_ours')}>
                                {page.countries
                                    .filter((country) => country.ours)
                                    .map((country) => (
                                        <option key={`ours-${country.code}`} value={country.code}>
                                            {country.name}
                                        </option>
                                    ))}
                            </optgroup>

                            <optgroup label={t('access::staff.countries_all')}>
                                {page.countries.map((country) => (
                                    <option key={country.code} value={country.code}>
                                        {country.name}
                                    </option>
                                ))}
                            </optgroup>
                        </Select>

                        <Select
                            id="locale"
                            label={t('access::staff.communication_language')}
                            helper={t('access::staff.communication_language_hint')}
                            error={form.errors.locale}
                            value={form.data.locale}
                            onChange={(event) => form.setData('locale', event.target.value)}
                        >
                            <option value="ar">العربية</option>
                            <option value="en">English</option>
                        </Select>

                        <Input
                            id="address"
                            label={t('access::staff.address')}
                            error={form.errors.address}
                            value={form.data.address}
                            onChange={(event) => form.setData('address', event.target.value)}
                            className="sm:col-span-2"
                        />
                    </section>
                ) : null}

                {step === 2 ? (
                    <div className="grid gap-6">
                        {page.maySetAdmin ? (
                            <div className="material-base px-4 py-3">
                                <Checkbox
                                    id="as_admin"
                                    checked={admin}
                                    onChange={(on) => {
                                        setAdmin(on);
                                        pick(null);
                                    }}
                                >
                                    <span className="grid gap-0.5">
                                        <span>{t('access::staff.as_admin')}</span>
                                        <span className="text-copy-13 text-ink-muted">{t('access::staff.as_admin_hint')}</span>
                                    </span>
                                </Checkbox>
                            </div>
                        ) : null}

                        <section className="material-base p-5">
                            <RadioGroup
                                name="role"
                                legend={
                                    <span className="grid gap-1">
                                        <span className="text-heading-16 text-ink">{t('access::staff.pick_role')}</span>
                                        <span className="text-copy-13 font-normal text-ink-muted">
                                            {t('access::staff.pick_role_hint')}
                                        </span>
                                    </span>
                                }
                                value={roleId ?? OWN_ROLE}
                                onChange={(value) => pick(value === OWN_ROLE ? null : value)}
                                options={[
                                    ...roles.map((role) => ({
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
                            <div className="grid gap-1">
                                <h2 className="text-heading-16 text-ink">{t('access::staff.where')}</h2>
                                <p className="text-copy-13 text-ink-muted">{t('access::staff.where_hint')}</p>
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
                            <div className="grid gap-1">
                                <h2 className="text-heading-16 text-ink">{t('access::staff.exceptions_title')}</h2>
                                <p className="text-copy-13 text-ink-muted">{t('access::staff.exceptions_hint')}</p>
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
                        <Button type="secondary" onClick={() => setStep(step - 1)}>
                            {t('access::staff.back')}
                        </Button>
                    ) : null}

                    <Button typeName="submit" loading={form.processing}>
                        {t(step < 3 ? 'access::staff.next' : 'access::staff.send_invitation')}
                    </Button>

                    <span className="text-copy-13 text-ink-muted">{t('access::staff.nothing_sent_yet')}</span>
                </div>
            </form>
        </AdminLayout>
    );
}
