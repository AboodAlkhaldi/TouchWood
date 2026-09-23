import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import type { StaffMemberPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| C2 - one staff member, with C4 to C9 as the things that can be done to them (frontend.md §3.3).
|
| Every button here was offered by Access, asked action by action, so nobody is shown one that
| refuses them when pressed. Offering is still not allowing: each handler asks again with the
| stores in hand.
|
| A Super Admin shows no management buttons at all - they are made and removed by console command
| only (access.md §1.6) - and nobody manages themselves.
*/

type Props = StaffMemberPage;

export default function Show(person: Props) {
    const t = useTranslator();
    const [editing, setEditing] = useState(false);
    const [changingEmail, setChangingEmail] = useState(false);

    const profile = useForm({
        first_name: person.firstName,
        last_name: person.lastName,
        job_title: person.jobTitle ?? '',
        date_of_birth: person.dateOfBirth ?? '',
        country: person.country ?? '',
        address: person.address ?? '',
        phone: person.phone ?? '',
    });

    const email = useForm({ email: '' });
    const post = (path: string) => router.post(`/admin/staff/${person.id}${path}`);

    return (
        <AdminLayout
            title={person.name}
            subtitle={person.roleName}
            action={
                <div className="flex flex-wrap gap-2">
                    {person.mayEditProfile ? (
                        <Button variant="outline" onClick={() => setEditing((open) => !open)}>
                            {t('access::staff.edit_profile')}
                        </Button>
                    ) : null}
                    {person.mayChangeEmail ? (
                        <Button variant="outline" onClick={() => setChangingEmail((open) => !open)}>
                            {t('access::staff.change_email')}
                        </Button>
                    ) : null}
                    {person.mayChangeRole ? (
                        <Button variant="outline" asChild>
                            <Link href={`/admin/staff/${person.id}/role`}>{t('access::staff.change_role')}</Link>
                        </Button>
                    ) : null}
                    {person.mayRefresh ? (
                        <Button variant="outline" onClick={() => post('/refresh')}>
                            {t('access::staff.refresh')}
                        </Button>
                    ) : null}
                    {person.mayResendInvitation ? (
                        <Button variant="outline" onClick={() => post('/invitation/resend')}>
                            {t('access::staff.resend_invitation')}
                        </Button>
                    ) : null}
                    {person.mayCancelInvitation ? (
                        <Button variant="outline" onClick={() => post('/invitation/cancel')}>
                            {t('access::staff.cancel_invitation')}
                        </Button>
                    ) : null}
                    {person.mayDisable ? (
                        <Button variant="destructive" onClick={() => post('/disable')}>
                            {t('access::staff.disable')}
                        </Button>
                    ) : null}
                    {person.mayEnable ? (
                        <Button onClick={() => post('/enable')}>{t('access::staff.enable')}</Button>
                    ) : null}
                </div>
            }
        >
            <div className="grid gap-6">
                <FormError />

                {person.isSuperAdmin ? (
                    <p className="rounded-md border border-line bg-surface-sunken px-4 py-3 text-sm text-ink-muted">
                        {t('access::staff.super_admin_hint')}
                    </p>
                ) : null}

                {changingEmail ? (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            email.post(`/admin/staff/${person.id}/email`);
                        }}
                        className="grid max-w-md gap-4 rounded-lg border border-line bg-surface p-4"
                    >
                        <div className="grid gap-0.5">
                            <h2 className="text-sm font-semibold text-ink">{t('access::staff.change_email')}</h2>
                            <p className="text-xs text-ink-muted">{t('access::staff.change_email_hint')}</p>
                        </div>

                        <Field id="new_email" label={t('access::staff.new_email')} error={email.errors.email}>
                            <Input
                                id="new_email"
                                type="email"
                                dir="ltr"
                                required
                                value={email.data.email}
                                onChange={(event) => email.setData('email', event.target.value)}
                            />
                        </Field>

                        <Button type="submit" disabled={email.processing}>
                            {t('access::staff.save')}
                        </Button>
                    </form>
                ) : null}

                <section className="grid gap-3 rounded-lg border border-line bg-surface p-4">
                    <h2 className="text-sm font-semibold text-ink">{t('access::staff.profile')}</h2>

                    {editing ? (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                profile.post(`/admin/staff/${person.id}/profile`);
                            }}
                            className="grid gap-4 sm:grid-cols-2"
                        >
                            <Field id="first_name" label={t('access::staff.first_name')} error={profile.errors.first_name}>
                                <Input
                                    id="first_name"
                                    required
                                    value={profile.data.first_name}
                                    onChange={(event) => profile.setData('first_name', event.target.value)}
                                />
                            </Field>

                            <Field id="last_name" label={t('access::staff.last_name')} error={profile.errors.last_name}>
                                <Input
                                    id="last_name"
                                    required
                                    value={profile.data.last_name}
                                    onChange={(event) => profile.setData('last_name', event.target.value)}
                                />
                            </Field>

                            <Field id="job_title" label={t('access::staff.job_title')} error={profile.errors.job_title}>
                                <Input
                                    id="job_title"
                                    value={profile.data.job_title}
                                    onChange={(event) => profile.setData('job_title', event.target.value)}
                                />
                            </Field>

                            <Field id="phone" label={t('access::staff.phone')} error={profile.errors.phone}>
                                <Input
                                    id="phone"
                                    dir="ltr"
                                    className="tw-figure"
                                    value={profile.data.phone}
                                    onChange={(event) => profile.setData('phone', event.target.value)}
                                />
                            </Field>

                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={profile.processing}>
                                    {t('access::staff.save')}
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <dl className="grid gap-2 text-sm sm:grid-cols-2">
                            <Detail label={t('access::staff.email')} value={person.email} ltr />
                            <Detail label={t('access::staff.phone')} value={person.phone} ltr />
                            <Detail label={t('access::staff.job_title')} value={person.jobTitle} />
                            <Detail label={t('access::staff.country')} value={person.country} />
                            <Detail label={t('access::staff.address')} value={person.address} />
                            <Detail
                                label={t('access::staff.communication_language')}
                                value={person.locale === 'en' ? 'English' : 'العربية'}
                            />
                        </dl>
                    )}
                </section>

                <section className="grid gap-2">
                    <div className="grid gap-0.5">
                        <h2 className="text-sm font-semibold text-ink">{t('access::staff.allows')}</h2>
                        <p className="text-xs text-ink-muted">
                            {t('access::staff.stores')}:{' '}
                            {person.allStores ? t('access::staff.every_store') : person.storeNames.join('، ')}
                        </p>
                    </div>

                    {person.actions.length === 0 ? (
                        <p className="rounded-lg border border-line bg-surface p-4 text-sm text-ink-muted">
                            {t('access::staff.no_role')}
                        </p>
                    ) : (
                        person.groups.map((group) => {
                            const inGroup = person.actions.filter((action) => action.group === group.key);

                            if (inGroup.length === 0) {
                                return null;
                            }

                            return (
                                <div key={group.key} className="rounded-lg border border-line bg-surface">
                                    <h3 className="border-b border-line px-4 py-2 text-xs font-semibold text-ink-muted uppercase">
                                        {group.label}
                                    </h3>
                                    <ul className="grid gap-1.5 p-4">
                                        {inGroup.map((action) => (
                                            <li key={action.name} className="grid gap-0.5 text-sm">
                                                <span className="text-ink">
                                                    {action.label}
                                                    {action.exception ? (
                                                        <span
                                                            className="ms-2 rounded-pill bg-warn-soft px-2 py-0.5 text-xs text-warn"
                                                            title={t('access::staff.exception_hint')}
                                                        >
                                                            {t('access::staff.exception')}
                                                        </span>
                                                    ) : null}
                                                </span>
                                                <span className="text-xs text-ink-muted">
                                                    {action.storeFree
                                                        ? t('access::staff.store_free')
                                                        : (action.storeNames ?? []).join('، ') ||
                                                          t('access::staff.every_store')}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            );
                        })
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}

function Detail({ label, value, ltr = false }: { label: string; value: string | null; ltr?: boolean }) {
    if (value === null || value === '') {
        return null;
    }

    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-ink-muted">{label}</dt>
            <dd className="text-ink" dir={ltr ? 'ltr' : undefined}>
                {value}
            </dd>
        </div>
    );
}
