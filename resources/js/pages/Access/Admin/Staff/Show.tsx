import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { AllowedActions } from '@/components/AllowedActions';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { TextField } from '@/components/Fields';
import { DialogError, FormError, useFreshRefusal } from '@/components/FormError';
import { Description, type DescriptionItem } from '@/components/geist-only/Description';
import { Note } from '@/components/Note';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Card, CardAction, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { toLatinDigits } from '@/lib/digits';
import { useList } from '@/lib/list';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { useReturnFocus } from '@/lib/use-return-focus';
import type { StaffMemberPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| C2 - one staff member, with C4 to C9 as the things that can be done to them (frontend.md §3.3).
|
| Every action here was offered by Access, asked action by action, so nobody is shown one that
| refuses them when pressed. Offering is still not allowing: each handler asks again with the
| stores in hand.
|
| A Super Admin shows no management actions at all - they are made and removed by console command
| only (access.md §1.6) - and nobody manages themselves.
|
| The page's header keeps one main button and puts the rest in a ⋯ menu, the destructive ones last
| after a divider (frontend.md §1.11, "Applied in the rebuild": this page is the first example;
| Geist's Button: "switch to a Menu ... when more than one related action shares a row"). Items
| that open a dialog end in "…". Editing the profile is the Profile card's own action.
|
| The two actions that take something away are confirmed first, as Geist asks of every destructive
| action: disabling somebody ends their sessions at once, so the person types their name before it
| happens (the Destructive Action Modal); cancelling an invitation kills its link but a new one can
| still be sent (access.md amendment 29), so a plain destructive confirmation that starts on its
| Cancel. Every action that posts says it is busy until the answer is back.
*/

type Props = StaffMemberPage;

type Action = { key: string; label: string; run: () => void; busy?: boolean; href?: string };

export default function Show(person: Props) {
    const t = useTranslator();
    const list = useList();
    const more = useRef<HTMLButtonElement>(null);
    const [editing, setEditing] = useState(false);
    const editButton = useRef<HTMLButtonElement>(null);
    const wasEditing = useRef(false);

    // Cancel and Save go away with the form, so focus goes back to Edit Profile, where it was
    // before (the review of batch A).
    useEffect(() => {
        if (wasEditing.current && !editing) {
            editButton.current?.focus();
        }
        wasEditing.current = editing;
    }, [editing]);
    const [changingEmail, setChangingEmail] = useState(false);
    const [disabling, setDisabling] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    // Only Disable's own refusal, never an older one from Enable or Resend (useFreshRefusal).
    const disableRefusal = useFreshRefusal(disabling);
    // The path of the action on its way to the server, so its own button says it is busy.
    const [busy, setBusy] = useState<string | null>(null);
    const emailFocus = useReturnFocus(changingEmail, more);
    const cancelFocus = useReturnFocus(cancelling, more);

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

    // The profile's boxes as typed (frontend.md §1.7), checked only while the card is being edited,
    // with Access's rules for a staff profile: a first name, last name and job title of at most 100
    // characters each, the job title as required as the names (StaffProfile::MAX_TEXT); a number
    // with its country code (PhoneNumber). Afresh each time the card is opened.
    const text = { required: true, length: { max: 100 } };
    const checks = useChecks(
        [
            { id: 'first_name', label: t('access::staff.first_name'), value: profile.data.first_name, rules: text, off: !editing },
            { id: 'last_name', label: t('access::staff.last_name'), value: profile.data.last_name, rules: text, off: !editing },
            { id: 'job_title', label: t('access::staff.job_title'), value: profile.data.job_title, rules: text, off: !editing },
            { id: 'phone', label: t('access::staff.phone'), value: profile.data.phone, rules: { required: true, phone: true }, off: !editing },
        ],
        editing,
    );
    // The new address as typed, as Access keeps one (EmailAddress): required, an address's shape, at
    // most 254 characters. Afresh each time the dialog opens.
    const emailChecks = useChecks(
        [{ id: 'new_email', label: t('access::staff.new_email'), value: email.data.email, rules: { required: true, email: true, length: { max: 254 } } }],
        changingEmail,
    );

    function post(path: string, done?: () => void) {
        router.post(`/admin/staff/${person.id}${path}`, {}, { onStart: () => setBusy(path), onFinish: () => setBusy(null), onSuccess: done });
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
            // Not sent when the summary is closed (amendment 57): no line then, never a guess.
            content: person.communicationLocale === null ? null : person.communicationLocale === 'en' ? 'English' : 'العربية',
        },
    ].filter((fact) => fact.content !== null && fact.content !== '');

    // Every action Access offered, in the order Geist's menu wants them: the everyday ones, then
    // the destructive ones last. The first everyday one is the page's main button.
    const everydayOffered: (Action | null)[] = [
        person.mayEnable ? { key: 'enable', label: t('access::staff.enable'), run: () => post('/enable'), busy: busy === '/enable' } : null,
        person.mayChangeRole ? { key: 'role', label: t('access::staff.change_role'), run: () => router.visit(`/admin/staff/${person.id}/role`), href: `/admin/staff/${person.id}/role` } : null,
        person.mayResendInvitation
            ? { key: 'resend', label: t('access::staff.resend_invitation'), run: () => post('/invitation/resend'), busy: busy === '/invitation/resend' }
            : null,
        person.mayChangeEmail ? { key: 'email', label: `${t('access::staff.change_email')}…`, run: () => setChangingEmail(true) } : null,
    ];
    const everyday = everydayOffered.filter((action): action is Action => action !== null);

    const destructiveOffered: (Action | null)[] = [
        person.mayCancelInvitation ? { key: 'cancel-invitation', label: `${t('access::staff.cancel_invitation')}…`, run: () => setCancelling(true) } : null,
        person.mayDisable ? { key: 'disable', label: `${t('access::staff.disable')}…`, run: () => setDisabling(true) } : null,
    ];
    const destructive = destructiveOffered.filter((action): action is Action => action !== null);

    // A dialog-opening action is never the main button: the main button does what it says.
    const main = everyday.find((action) => action.key !== 'email') ?? null;
    const rest = everyday.filter((action) => action !== main);
    // A menu closes as its item is chosen, so the item cannot show that it is busy: the ⋯ button
    // that holds it does, until the answer is back (the review of batch A).
    const menuBusy = rest.some((action) => action.busy === true);

    return (
        <AdminLayout
            title={person.name}
            subtitle={person.roleName}
            breadcrumbs={[{ label: t('access::staff.title'), href: '/admin/staff' }]}
            action={
                main === null && destructive.length === 0 ? undefined : (
                    <div className="flex items-center gap-2">
                        {main === null ? null : main.href === undefined ? (
                            <ActionButton loading={main.busy} onClick={main.run} data-test={`action-${main.key}`}>
                                {main.label}
                            </ActionButton>
                        ) : (
                            <Button asChild data-test={`action-${main.key}`}>
                                <Link href={main.href}>{main.label}</Link>
                            </Button>
                        )}

                        {rest.length === 0 && destructive.length === 0 ? null : (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        ref={more}
                                        variant="outline"
                                        size="icon"
                                        aria-label={t('ui.more_actions')}
                                        aria-busy={menuBusy || undefined}
                                        title={t('ui.more_actions')}
                                        data-test="more-actions"
                                    >
                                        {menuBusy ? <Spinner aria-label={t('ui.loading')} /> : <MoreHorizontal aria-hidden="true" />}
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="min-w-56">
                                    {rest.map((action) =>
                                        // A page to go to is a link in the menu too, so it can be opened in a new tab like any link.
                                        action.href !== undefined ? (
                                            <DropdownMenuItem key={action.key} asChild data-test={`action-${action.key}`}>
                                                <Link href={action.href}>{action.label}</Link>
                                            </DropdownMenuItem>
                                        ) : (
                                            <DropdownMenuItem
                                                key={action.key}
                                                // Busy, it stays in reach and says so (aria-disabled), as a
                                                // locked item does: Radix's `disabled` would drop it from the keys.
                                                aria-disabled={action.busy || undefined}
                                                onSelect={(event) => {
                                                    if (action.busy) {
                                                        event.preventDefault();

                                                        return;
                                                    }

                                                    action.run();
                                                }}
                                                data-test={`action-${action.key}`}
                                            >
                                                {action.label}
                                            </DropdownMenuItem>
                                        ),
                                    )}
                                    {rest.length > 0 && destructive.length > 0 ? <DropdownMenuSeparator /> : null}
                                    {destructive.map((action) => (
                                        <DropdownMenuItem key={action.key} variant="destructive" onSelect={action.run} data-test={`action-${action.key}`}>
                                            {action.label}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                )
            }
        >
            <div className="grid gap-6">
                <FormError />

                {person.isSuperAdmin ? <Note variant="secondary">{t('access::staff.super_admin_hint')}</Note> : null}

                <Card className="material-base gap-0 border-0 py-0">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            // The page stays as it is while the answer comes back, so the card can close itself
                            // and hand focus back; the new values arrive as props.
                            checks.submit(() => profile.post(`/admin/staff/${person.id}/profile`, { preserveState: true, onSuccess: () => setEditing(false) }));
                        }}
                    >
                        <CardHeader className="px-6 pt-5 pb-4">
                            <CardTitle className="text-heading-16 text-ink">
                                <h2>{t('access::staff.profile')}</h2>
                            </CardTitle>
                            {person.mayEditProfile && !editing ? (
                                <CardAction>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        ref={editButton}
                                        aria-expanded={false}
                                        aria-controls="profile-form"
                                        onClick={() => setEditing(true)}
                                        data-test="edit-profile"
                                    >
                                        {t('access::staff.edit_profile')}
                                    </Button>
                                </CardAction>
                            ) : null}
                        </CardHeader>

                        <CardContent className="px-6 pb-5" id="profile-form">
                            {editing ? (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField
                                        id="first_name"
                                        label={t('access::staff.first_name')}
                                        check={checks.box('first_name', profile.errors.first_name)}
                                        required
                                        autoFocus
                                        value={profile.data.first_name}
                                        onChange={(event) => profile.setData('first_name', event.target.value)}
                                    />
                                    <TextField
                                        id="last_name"
                                        label={t('access::staff.last_name')}
                                        check={checks.box('last_name', profile.errors.last_name)}
                                        required
                                        value={profile.data.last_name}
                                        onChange={(event) => profile.setData('last_name', event.target.value)}
                                    />
                                    <TextField
                                        id="job_title"
                                        label={t('access::staff.job_title')}
                                        check={checks.box('job_title', profile.errors.job_title)}
                                        required
                                        value={profile.data.job_title}
                                        onChange={(event) => profile.setData('job_title', event.target.value)}
                                    />
                                    <TextField
                                        id="phone"
                                        label={t('access::staff.phone')}
                                        check={checks.box('phone', profile.errors.phone)}
                                        dir="ltr"
                                        inputClassName="tw-figure"
                                        required
                                        value={profile.data.phone}
                                        onChange={(event) => profile.setData('phone', toLatinDigits(event.target.value))}
                                    />
                                </div>
                            ) : (
                                <Description items={facts} />
                            )}
                        </CardContent>

                        {editing ? (
                            <CardFooter className="justify-end gap-2 border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        profile.reset();
                                        profile.clearErrors();
                                        setEditing(false);
                                    }}
                                >
                                    {t('access::staff.cancel')}
                                </Button>
                                <ActionButton type="submit" loading={profile.processing} disabledReason={checks.reason}>
                                    {t('access::staff.save')}
                                </ActionButton>
                            </CardFooter>
                        ) : null}
                    </form>
                </Card>

                <section className="grid gap-3">
                    <div className="grid gap-1">
                        <h2 className="text-heading-16 text-ink">{t('access::staff.allows')}</h2>
                        <p className="text-copy-13 text-ink-muted">
                            {t('access::staff.stores')}: {person.allStores ? t('access::staff.every_store') : list(person.storeNames)}
                        </p>
                    </div>

                    {person.actions.length === 0 ? (
                        <Empty className="material-base">
                            <EmptyHeader>
                                <EmptyTitle>{t('access::staff.no_role_title')}</EmptyTitle>
                                <EmptyDescription>{t('access::staff.no_role')}</EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <AllowedActions
                            groups={person.groups}
                            rows={person.actions.map((action) => ({
                                key: action.name,
                                group: action.group,
                                label: action.label,
                                detail: action.storeFree ? t('access::staff.store_free') : list(action.storeNames ?? []) || t('access::staff.every_store'),
                                badge: action.exception ? { text: t('access::staff.exception'), hint: t('access::staff.exception_hint') } : undefined,
                            }))}
                        />
                    )}
                </section>
            </div>

            <Dialog
                open={changingEmail}
                onOpenChange={(open) => {
                    if (!email.processing) {
                        setChangingEmail(open);
                    }
                }}
            >
                <DialogContent showCloseButton={false} onCloseAutoFocus={emailFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 sm:max-w-md">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            emailChecks.submit(() =>
                                email.post(`/admin/staff/${person.id}/email`, {
                                    onSuccess: () => {
                                        email.reset();
                                        setChangingEmail(false);
                                    },
                                }),
                            );
                        }}
                    >
                        <div className="grid gap-4 p-6">
                            <DialogHeader>
                                <DialogTitle className="text-heading-20 text-ink">{t('access::staff.change_email')}</DialogTitle>
                                <DialogDescription className="text-copy-14 text-ink-muted">{t('access::staff.change_email_hint')}</DialogDescription>
                            </DialogHeader>
                            <TextField
                                id="new_email"
                                type="email"
                                label={t('access::staff.new_email')}
                                check={emailChecks.box('new_email', email.errors.email)}
                                dir="ltr"
                                required
                                autoFocus
                                value={email.data.email}
                                onChange={(event) => email.setData('email', event.target.value)}
                            />
                            <DialogError open={changingEmail} />
                        </div>
                        <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                            <Button type="button" variant="outline" disabled={email.processing} onClick={() => setChangingEmail(false)}>
                                {t('ui.cancel')}
                            </Button>
                            <ActionButton type="submit" loading={email.processing} disabledReason={emailChecks.reason} data-test="change-email">
                                {t('access::staff.change_email')}
                            </ActionButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <DestructiveActionDialog
                open={disabling}
                onOpenChange={setDisabling}
                title={t('access::staff.disable')}
                description={t('access::staff.disable_hint')}
                verificationPhrase={person.name}
                verificationLabel={t('access::staff.verification_label')}
                loading={busy === '/disable'}
                error={disableRefusal}
                onConfirm={() => post('/disable', () => setDisabling(false))}
                returnFocusTo={more}
            />

            {/* A plain destructive confirmation: it starts on its Cancel, so Enter keeps the
                invitation (Geist's Modal), and an outside click does not dismiss it. */}
            <AlertDialog open={cancelling} onOpenChange={(open) => (busy === null ? setCancelling(open) : undefined)}>
                <AlertDialogContent onCloseAutoFocus={cancelFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 data-[size=default]:sm:max-w-md">
                    <div className="grid gap-4 p-6">
                        <AlertDialogHeader>
                            <AlertDialogTitle className="text-heading-20 text-ink">{t('access::staff.cancel_invitation')}</AlertDialogTitle>
                            <AlertDialogDescription className="text-copy-14 text-ink-muted">{t('access::staff.cancel_invitation_body')}</AlertDialogDescription>
                        </AlertDialogHeader>
                        {/* A refusal keeps the dialog open, so it is said here, where the person is looking. */}
                        <DialogError open={cancelling} />
                    </div>
                    <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                        <AlertDialogCancel disabled={busy !== null} data-test="modal-cancel">
                            {t('access::staff.keep_invitation')}
                        </AlertDialogCancel>
                        <ActionButton
                            variant="destructive"
                            loading={busy === '/invitation/cancel'}
                            onClick={() => post('/invitation/cancel', () => setCancelling(false))}
                            data-test="confirm-cancel-invitation"
                        >
                            {t('access::staff.cancel_invitation')}
                        </ActionButton>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AdminLayout>
    );
}

/** A value read left to right inside an Arabic page - an address, a number - or nothing. */
function ltr(value: string | null): ReactNode {
    return value === null || value === '' ? null : <bdi dir="ltr">{value}</bdi>;
}
