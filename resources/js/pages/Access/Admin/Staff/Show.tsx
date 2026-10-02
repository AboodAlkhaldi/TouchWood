import { useState, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { DialogError, FormError, useFreshRefusal } from '@/components/FormError';
import {
    Badge,
    Button,
    ButtonLink,
    Description,
    DestructiveActionModal,
    EmptyState,
    Fieldset,
    Input,
    Modal,
    ModalCancel,
    Note,
    type DescriptionItem,
} from '@/components/geist';
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
|
| In Geist's parts (frontend.md 1.10). The two actions that take something away are confirmed in a
| Modal first, as Geist asks of every destructive action: disabling somebody ends their sessions at
| once, so the person types their name before it happens (the Destructive Action Modal); cancelling
| an invitation kills its link but a new one can still be sent (access.md amendment 29), so a plain
| destructive Modal. Every button that posts shows it is busy until the answer is back.
*/

type Props = StaffMemberPage;

export default function Show(person: Props) {
    const t = useTranslator();
    const [editing, setEditing] = useState(false);
    const [changingEmail, setChangingEmail] = useState(false);
    const [disabling, setDisabling] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    // Only Disable's own refusal, never an older one from Refresh or Resend (useFreshRefusal).
    const disableRefusal = useFreshRefusal(disabling);
    // The path of the action on its way to the server, so its own button says it is busy.
    const [busy, setBusy] = useState<string | null>(null);

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

    function post(path: string, done?: () => void) {
        router.post(
            `/admin/staff/${person.id}${path}`,
            {},
            { onStart: () => setBusy(path), onFinish: () => setBusy(null), onSuccess: done },
        );
    }

    // What is known about them, and only that: an admin seen by somebody who is not a Super Admin
    // arrives with these empty, and an empty line would only say that something is hidden.
    const facts: DescriptionItem[] = [
        { title: t('access::staff.email'), content: ltr(person.email) },
        { title: t('access::staff.phone'), content: ltr(person.phone) },
        { title: t('access::staff.job_title'), content: person.jobTitle },
        { title: t('access::staff.country'), content: person.country },
        { title: t('access::staff.address'), content: person.address },
        {
            title: t('access::staff.communication_language'),
            content: person.communicationLocale === 'en' ? 'English' : 'العربية',
        },
    ].filter((fact) => fact.content !== null && fact.content !== '');

    return (
        <AdminLayout
            title={person.name}
            subtitle={person.roleName}
            breadcrumbs={[{ label: t('access::staff.title'), href: '/admin/staff' }]}
            action={
                <div className="flex flex-wrap gap-2">
                    {person.mayEditProfile ? (
                        <Button type="secondary" onClick={() => setEditing((open) => !open)}>
                            {t('access::staff.edit_profile')}
                        </Button>
                    ) : null}
                    {person.mayChangeEmail ? (
                        <Button type="secondary" onClick={() => setChangingEmail((open) => !open)}>
                            {t('access::staff.change_email')}
                        </Button>
                    ) : null}
                    {person.mayChangeRole ? (
                        <ButtonLink href={`/admin/staff/${person.id}/role`} type="secondary">
                            {t('access::staff.change_role')}
                        </ButtonLink>
                    ) : null}
                    {person.mayRefresh ? (
                        <Button type="secondary" loading={busy === '/refresh'} onClick={() => post('/refresh')}>
                            {t('access::staff.refresh')}
                        </Button>
                    ) : null}
                    {person.mayResendInvitation ? (
                        <Button
                            type="secondary"
                            loading={busy === '/invitation/resend'}
                            onClick={() => post('/invitation/resend')}
                        >
                            {t('access::staff.resend_invitation')}
                        </Button>
                    ) : null}
                    {person.mayCancelInvitation ? (
                        <Button type="secondary" onClick={() => setCancelling(true)}>
                            {t('access::staff.cancel_invitation')}
                        </Button>
                    ) : null}
                    {person.mayDisable ? (
                        <Button type="error" onClick={() => setDisabling(true)}>
                            {t('access::staff.disable')}
                        </Button>
                    ) : null}
                    {person.mayEnable ? (
                        <Button loading={busy === '/enable'} onClick={() => post('/enable')}>
                            {t('access::staff.enable')}
                        </Button>
                    ) : null}
                </div>
            }
        >
            <div className="grid gap-6">
                <FormError />

                {person.isSuperAdmin ? <Note variant="secondary">{t('access::staff.super_admin_hint')}</Note> : null}

                {changingEmail ? (
                    <div className="max-w-xl">
                        <Fieldset
                            as="form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                email.post(`/admin/staff/${person.id}/email`);
                            }}
                            title={t('access::staff.change_email')}
                            subtitle={t('access::staff.change_email_hint')}
                            footerAction={
                                <Button typeName="submit" loading={email.processing}>
                                    {t('access::staff.save')}
                                </Button>
                            }
                        >
                            <Input
                                id="new_email"
                                type="email"
                                label={t('access::staff.new_email')}
                                error={email.errors.email}
                                dir="ltr"
                                required
                                value={email.data.email}
                                onChange={(event) => email.setData('email', event.target.value)}
                            />
                        </Fieldset>
                    </div>
                ) : null}

                {editing ? (
                    <Fieldset
                        as="form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            profile.post(`/admin/staff/${person.id}/profile`);
                        }}
                        title={t('access::staff.profile')}
                        footerAction={
                            <Button typeName="submit" loading={profile.processing}>
                                {t('access::staff.save')}
                            </Button>
                        }
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Input
                                id="first_name"
                                label={t('access::staff.first_name')}
                                error={profile.errors.first_name}
                                required
                                value={profile.data.first_name}
                                onChange={(event) => profile.setData('first_name', event.target.value)}
                            />

                            <Input
                                id="last_name"
                                label={t('access::staff.last_name')}
                                error={profile.errors.last_name}
                                required
                                value={profile.data.last_name}
                                onChange={(event) => profile.setData('last_name', event.target.value)}
                            />

                            <Input
                                id="job_title"
                                label={t('access::staff.job_title')}
                                error={profile.errors.job_title}
                                value={profile.data.job_title}
                                onChange={(event) => profile.setData('job_title', event.target.value)}
                            />

                            <Input
                                id="phone"
                                label={t('access::staff.phone')}
                                error={profile.errors.phone}
                                dir="ltr"
                                inputClassName="tw-figure"
                                value={profile.data.phone}
                                onChange={(event) => profile.setData('phone', event.target.value)}
                            />
                        </div>
                    </Fieldset>
                ) : (
                    <Fieldset title={t('access::staff.profile')}>
                        <Description items={facts} />
                    </Fieldset>
                )}

                <section className="grid gap-3">
                    <div className="grid gap-1">
                        <h2 className="text-heading-16 text-ink">{t('access::staff.allows')}</h2>
                        <p className="text-copy-13 text-ink-muted">
                            {t('access::staff.stores')}:{' '}
                            {person.allStores ? t('access::staff.every_store') : person.storeNames.join('، ')}
                        </p>
                    </div>

                    {person.actions.length === 0 ? (
                        <EmptyState title={t('access::staff.no_role_title')} description={t('access::staff.no_role')} />
                    ) : (
                        person.groups.map((group) => {
                            const inGroup = person.actions.filter((action) => action.group === group.key);

                            if (inGroup.length === 0) {
                                return null;
                            }

                            return (
                                <div key={group.key} className="material-base overflow-hidden">
                                    <h3 className="border-b border-line px-4 py-2 text-label-13 font-medium text-ink-muted">
                                        {group.label}
                                    </h3>
                                    <ul className="grid gap-2 p-4">
                                        {inGroup.map((action) => (
                                            <li key={action.name} className="grid gap-0.5">
                                                <span className="flex flex-wrap items-center gap-2 text-copy-14 text-ink">
                                                    {action.label}
                                                    {action.exception ? (
                                                        <Badge
                                                            variant="amber-subtle"
                                                            size="small"
                                                            title={t('access::staff.exception_hint')}
                                                        >
                                                            {t('access::staff.exception')}
                                                        </Badge>
                                                    ) : null}
                                                </span>
                                                <span className="text-copy-13 text-ink-muted">
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

            <DestructiveActionModal
                open={disabling}
                onOpenChange={setDisabling}
                title={t('access::staff.disable')}
                description={t('access::staff.disable_hint')}
                verificationPhrase={person.name}
                verificationLabel={t('access::staff.verification_label')}
                loading={busy === '/disable'}
                error={disableRefusal}
                onConfirm={() => post('/disable', () => setDisabling(false))}
            />

            <Modal
                open={cancelling}
                onOpenChange={setCancelling}
                destructive
                title={t('access::staff.cancel_invitation')}
                description={t('access::staff.cancel_invitation_body')}
                actions={
                    <>
                        <ModalCancel
                            onClick={() => setCancelling(false)}
                            label={t('access::staff.keep_invitation')}
                            disabled={busy !== null}
                        />
                        <Button
                            type="error"
                            loading={busy === '/invitation/cancel'}
                            onClick={() => post('/invitation/cancel', () => setCancelling(false))}
                        >
                            {t('access::staff.cancel_invitation')}
                        </Button>
                    </>
                }
            >
                {/* A refusal keeps the dialog open, so it is said here, where the person is looking. */}
                <DialogError open={cancelling} />
            </Modal>
        </AdminLayout>
    );
}

/** A value read left to right inside an Arabic page - an address, a number - or nothing. */
function ltr(value: string | null): ReactNode {
    return value === null || value === '' ? null : <bdi dir="ltr">{value}</bdi>;
}
