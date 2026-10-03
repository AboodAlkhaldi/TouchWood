import { useEffect, useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { CountryCombobox } from '@/components/CountryCombobox';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { ProgressStops } from '@/components/geist-only/ProgressStops';
import { PermissionPicker } from '@/components/PermissionPicker';
import { RoleChoice } from '@/components/RoleChoice';
import { ExceptionList, SELECTED_STORES, StoreChoice } from '@/components/RoleStores';
import type { ExceptionRow } from '@/components/RoleStores';
import { Button } from '@/components/ui/button';
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { useTranslator } from '@/lib/t';
import type { InviteStaffPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| C3 - inviting a staff member (frontend.md §3.3).
|
| Three steps: who they are, what they may do, and where. **Nothing is sent until the last step**
| [decided 2026-09-19] - leaving halfway writes nothing and creates nobody, so there is no half-made
| account for an admin to find later and wonder about.
|
| A refusal that belongs to an earlier step takes the person back to it, rather than showing a
| message next to a field they cannot see.
|
| shadcn's parts with Geist's rules (frontend.md §1.11):
| - the steps are Geist's Progress with stops ("multi-step setup"), the stage said next to the bar -
|   "Step 2 of 3 · Role";
| - each step's fields are a FieldSet named by its legend, so a screen reader hears "Profile";
| - the country is a combobox, since the list is the whole world (Geist's Select is for short lists);
| - "An Admin" is one on/off choice, so a Switch with its hint as the description (Geist's Toggle);
| - the role is shadcn's choice cards (Geist's Choicebox), as on one person's role screen.
| The button that moves on stays a submit, so Enter on any field goes to the next step exactly as
| the button does.
*/

type Props = InviteStaffPage;

const PROFILE_FIELDS = ['email', 'first_name', 'last_name', 'job_title', 'date_of_birth', 'country', 'address', 'phone', 'locale'];

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
        locale: page.communicationLocale,
        access_level: SELECTED_STORES,
        store_ids: [] as string[],
        exceptions: [] as ExceptionRow[],
    });

    // An admin is not tied to a store, and only a Super Admin may bring one in.
    const permissions = admin ? page.adminPermissions : page.permissions;
    const level = admin ? 'ADMIN' : 'STAFF';
    const roles = useMemo(() => page.savedRoles.filter((role) => role.level === level), [page.savedRoles, level]);

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

    const steps = [t('access::staff.step_profile'), t('access::staff.step_role'), t('access::staff.step_stores')];
    const stage = `${t('access::staff.step_of', { step, total: steps.length })} · ${steps[step - 1] ?? ''}`;

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
                <div className="grid gap-2">
                    <p className="tw-figure text-label-13 text-ink-muted" aria-hidden="true">
                        {stage}
                    </p>
                    <ProgressStops
                        value={step}
                        max={steps.length}
                        label={t('access::staff.invite_title')}
                        valueText={stage}
                        // A stop where each step ends, named by the step it closes (Geist: a stop
                        // with no label is noise).
                        stops={steps.slice(0, -1).map((name, index) => ({ value: index + 1, tooltip: name }))}
                    />
                </div>

                <FormError />

                {step === 1 ? (
                    <FieldSet className="material-base gap-4 p-5">
                        <FieldLegend className="mb-0 text-heading-16 text-ink">{t('access::staff.profile')}</FieldLegend>
                        <FieldGroup className="grid gap-4 sm:grid-cols-2">
                            <TextField
                                id="first_name"
                                label={t('access::staff.first_name')}
                                error={form.errors.first_name}
                                required
                                value={form.data.first_name}
                                onChange={(event) => form.setData('first_name', event.target.value)}
                            />
                            <TextField
                                id="last_name"
                                label={t('access::staff.last_name')}
                                error={form.errors.last_name}
                                required
                                value={form.data.last_name}
                                onChange={(event) => form.setData('last_name', event.target.value)}
                            />
                            <TextField
                                id="email"
                                type="email"
                                label={t('access::staff.email')}
                                error={form.errors.email}
                                dir="ltr"
                                required
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                            <TextField
                                id="phone"
                                label={t('access::staff.phone')}
                                error={form.errors.phone}
                                dir="ltr"
                                inputClassName="tw-figure"
                                required
                                value={form.data.phone}
                                onChange={(event) => form.setData('phone', event.target.value)}
                            />
                            <TextField
                                id="job_title"
                                label={t('access::staff.job_title')}
                                error={form.errors.job_title}
                                required
                                value={form.data.job_title}
                                onChange={(event) => form.setData('job_title', event.target.value)}
                            />
                            <TextField
                                id="date_of_birth"
                                type="date"
                                label={t('access::staff.date_of_birth')}
                                error={form.errors.date_of_birth}
                                dir="ltr"
                                required
                                value={form.data.date_of_birth}
                                onChange={(event) => form.setData('date_of_birth', event.target.value)}
                            />
                            <CountryCombobox
                                id="country"
                                label={t('access::staff.country')}
                                countries={page.countries}
                                value={form.data.country}
                                onChange={(code) => form.setData('country', code)}
                                error={form.errors.country}
                            />
                            <SelectField
                                id="locale"
                                label={t('access::staff.communication_language')}
                                helper={t('access::staff.communication_language_hint')}
                                error={form.errors.locale}
                                value={form.data.locale}
                                onChange={(event) => form.setData('locale', event.target.value)}
                            >
                                <NativeSelectOption value="ar">العربية</NativeSelectOption>
                                <NativeSelectOption value="en">English</NativeSelectOption>
                            </SelectField>
                            <TextField
                                id="address"
                                label={t('access::staff.address')}
                                error={form.errors.address}
                                value={form.data.address}
                                onChange={(event) => form.setData('address', event.target.value)}
                                className="sm:col-span-2"
                            />
                        </FieldGroup>
                    </FieldSet>
                ) : null}

                {step === 2 ? (
                    <div className="grid gap-6">
                        {page.maySetAdmin ? (
                            <Field orientation="horizontal" className="material-base px-4 py-3">
                                <FieldContent>
                                    <FieldLabel htmlFor="as_admin" className="text-label-14 text-ink">
                                        {t('access::staff.as_admin')}
                                    </FieldLabel>
                                    <FieldDescription className="text-copy-13 text-ink-muted">{t('access::staff.as_admin_hint')}</FieldDescription>
                                </FieldContent>
                                <Switch
                                    id="as_admin"
                                    checked={admin}
                                    onCheckedChange={(on) => {
                                        setAdmin(on);
                                        pick(null);
                                    }}
                                    className="data-[state=unchecked]:bg-ink-subtle"
                                />
                            </Field>
                        ) : null}

                        <RoleChoice roles={roles} value={roleId} edited={edited} onPick={pick} />

                        <PermissionPicker permissions={permissions} groups={page.groups} chosen={chosen} onChange={setChosen} />
                    </div>
                ) : null}

                {step === 3 ? (
                    <div className="grid gap-6">
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
                                onLevel={(next) => form.setData('access_level', next)}
                                onChosen={(ids) => form.setData('store_ids', ids)}
                            />
                        </FieldSet>

                        <FieldSet className="gap-3">
                            <FieldLegend className="mb-0 text-heading-16 text-ink">{t('access::staff.exceptions_title')}</FieldLegend>
                            <FieldDescription className="text-copy-13 text-ink-muted">{t('access::staff.exceptions_hint')}</FieldDescription>
                            <ExceptionList
                                permissions={permissions}
                                chosen={chosen}
                                stores={page.stores}
                                rows={form.data.exceptions}
                                onChange={(rows) => form.setData('exceptions', rows)}
                            />
                        </FieldSet>
                    </div>
                ) : null}

                <div className="flex flex-wrap items-center gap-3">
                    {step > 1 ? (
                        <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>
                            {t('access::staff.back')}
                        </Button>
                    ) : null}

                    <ActionButton type="submit" loading={form.processing}>
                        {t(step < 3 ? 'access::staff.next' : 'access::staff.send_invitation')}
                    </ActionButton>

                    <span className="text-copy-13 text-ink-muted">{t('access::staff.nothing_sent_yet')}</span>
                </div>
            </form>
        </AdminLayout>
    );
}
