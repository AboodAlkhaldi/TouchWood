import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { AddressLines } from '@/components/AddressLines';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { DialogError, FormError } from '@/components/FormError';
import { Description } from '@/components/geist-only/Description';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Time } from '@/components/Time';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';
import { tone } from '@/lib/tones';
import type { AddressRow, CustomerAddressGroup, CustomerDetailsPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| G2 - one customer (frontend.md §3.7).
|
| **Read only, except for four things.** A customer's name, language and addresses are their own;
| staff may block an account, unblock it, start the customer's own fourteen-day deletion at their
| request, and stop one - each with a reason, and each admin-only (R6).
|
| Every action is offered only to somebody who holds it, and the handler behind it asks again with
| the stores in hand: offering is never allowing (handoff §19).
|
| A reason is asked for before anything happens rather than after, because the audit entry is only
| as useful as the sentence somebody wrote in it.
|
| shadcn's parts with Geist's rules (frontend.md §1.11): the summary is a Card whose facts are
| Geist's Description; one status badge, the day an account closes said as a fact of its own; the
| addresses are Items, grouped by store, an off store's marked Off (access.md amendment 58(d)). Each
| action is a Card whose button only opens its confirmation, so it is never red; red is for the
| confirmation itself. Blocking and closing are destructive, so they are an AlertDialog that starts
| on Cancel and that Enter never confirms (Geist's Modal); unblocking and stopping a closing undo
| those, so a plain Dialog where Enter in the reason sends it.
*/

type Props = CustomerDetailsPage;

export default function Show({ customer, communicationLocale, addresses, mayBlock, mayUnblock, mayStartDeletion, mayCancelDeletion, deletionDays }: Props) {
    const t = useTranslator();

    return (
        <AdminLayout
            title={customer.name}
            subtitle={t('access::customers.title')}
            breadcrumbs={[{ label: t('access::customers.title'), href: '/admin/customers' }]}
        >
            <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="grid content-start gap-6">
                    <Card className="material-base gap-4 border-0 py-6">
                        <CardHeader className="px-6">
                            <CardTitle className="text-heading-16 text-ink">
                                <h2>{customer.name}</h2>
                            </CardTitle>
                            <CardDescription>
                                <bdi dir="ltr" className="text-copy-14 text-ink-muted">
                                    {customer.email}
                                </bdi>
                            </CardDescription>
                            <CardAction>
                                <Status status={customer.status} anonymized={customer.anonymized} />
                            </CardAction>
                        </CardHeader>

                        <CardContent className="px-6">
                            <Description
                                items={[
                                    { title: t('access::customers.type'), content: t(`access::customers.account_type.${customer.accountType}`) },
                                    {
                                        // An off home store is named, flagged (access.md amendment 58(d)).
                                        title: t('access::customers.home_store'),
                                        content: (
                                            <span className="inline-flex items-center gap-1.5">
                                                {customer.homeStore}
                                                {customer.homeStoreIsActive ? null : <StoreOffBadge />}
                                            </span>
                                        ),
                                    },
                                    {
                                        // No number is an unknown value, which Geist writes as an em dash.
                                        title: t('access::customers.phone'),
                                        content:
                                            customer.phone === null ? null : (
                                                <bdi dir="ltr" className="tw-figure">
                                                    {customer.phone}
                                                </bdi>
                                            ),
                                    },
                                    { title: t('access::customers.communication_language'), content: t(`access::account.language.${communicationLocale}`) },
                                    {
                                        title: t('access::customers.email_verified'),
                                        content: t(customer.emailVerified ? 'access::customers.confirmed' : 'access::customers.not_confirmed'),
                                    },
                                    {
                                        title: t('access::customers.phone_verified'),
                                        content: t(customer.phoneVerified ? 'access::customers.confirmed' : 'access::customers.not_confirmed'),
                                    },
                                    // On a detail page the full moment is the text (frontend.md §1.10).
                                    { title: t('access::customers.registered'), content: <Time value={customer.registeredAt} mode="absolute" /> },
                                    ...(customer.deletionScheduledFor === null || customer.anonymized
                                        ? []
                                        : [
                                              {
                                                  title: t('access::customers.closing_on'),
                                                  content: <Time value={customer.deletionScheduledFor} mode="absolute" />,
                                                  'data-test': 'deletion-pending',
                                              },
                                          ]),
                                ]}
                            />
                        </CardContent>

                        <CardFooter className="px-6">
                            <p className="text-copy-13 text-ink-muted">{t('access::customers.profile_is_theirs')}</p>
                        </CardFooter>
                    </Card>

                    <section className="grid gap-3">
                        <h2 className="text-heading-16 text-ink">{t('access::customers.addresses')}</h2>

                        {addresses.length === 0 ? (
                            <Empty className="material-base">
                                <EmptyHeader>
                                    <EmptyTitle>{t('access::customers.no_addresses_title')}</EmptyTitle>
                                    <EmptyDescription>{t('access::customers.no_addresses')}</EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            addresses.map((group) => <Addresses key={group.storeId} group={group} />)
                        )}
                    </section>
                </div>

                <aside className="grid content-start gap-4">
                    <h2 className="text-heading-16 text-ink">{t('access::customers.actions')}</h2>

                    <FormError />

                    {/* Each one is offered only when Access says so: only to somebody who holds
                        the permission, and only when it is the thing that can happen next. This
                        screen works none of that out for itself. */}
                    {mayBlock ? (
                        <Action
                            name="block"
                            url={`/admin/customers/${customer.id}/block`}
                            title={t('access::customers.block')}
                            body={t('access::customers.block_body')}
                            destructive
                        />
                    ) : null}

                    {mayUnblock ? (
                        <Action
                            name="unblock"
                            url={`/admin/customers/${customer.id}/unblock`}
                            title={t('access::customers.unblock')}
                            body={t('access::customers.unblock_body')}
                        />
                    ) : null}

                    {mayStartDeletion ? (
                        <Action
                            name="start-deletion"
                            url={`/admin/customers/${customer.id}/delete`}
                            title={t('access::customers.start_deletion')}
                            body={t('access::customers.start_deletion_body', { count: deletionDays })}
                            destructive
                        />
                    ) : null}

                    {mayCancelDeletion ? (
                        <Action
                            name="cancel-deletion"
                            url={`/admin/customers/${customer.id}/delete/cancel`}
                            title={t('access::customers.cancel_deletion')}
                            body={t('access::customers.cancel_deletion_body')}
                        />
                    ) : null}
                </aside>
            </div>
        </AdminLayout>
    );
}

/** One badge (Geist): the account's state, or Anonymized once it is gone. */
function Status({ status, anonymized }: { status: string; anonymized: boolean }) {
    const t = useTranslator();

    if (anonymized) {
        return <Badge className={tone('gray-subtle')}>{t('access::customers.anonymized')}</Badge>;
    }

    return <Badge className={tone(status === 'BLOCKED' ? 'red-subtle' : 'green-subtle')}>{t(`access::customers.account_status.${status}`)}</Badge>;
}

/** A customer's addresses in one store, exactly as that store lays them out. */
function Addresses({ group }: { group: CustomerAddressGroup }) {
    return (
        <div className="grid gap-2" data-test={`addresses-${group.storeId}`}>
            {/* An off store's addresses are shown to staff, marked; the customer cannot use them
                until it is on again (access.md amendment 58(d)). */}
            <h3 className="inline-flex items-center gap-1.5 text-heading-14 text-ink">
                {group.storeName}
                {group.isActive ? null : <StoreOffBadge />}
            </h3>

            <div role="list" className="grid gap-2 sm:grid-cols-2">
                {group.addresses.map((address: AddressRow) => (
                    <div key={address.id} role="listitem">
                        <Item variant="outline" className="material-base h-full items-start border-0">
                            <ItemContent className="gap-0.5">
                                <ItemTitle className="text-label-14 text-ink">{address.label}</ItemTitle>
                                <ItemDescription className="line-clamp-none text-copy-14 text-ink-muted">{address.recipientName}</ItemDescription>
                                <ItemDescription className="tw-figure line-clamp-none text-copy-14 text-ink-muted" dir="ltr">
                                    {address.phone}
                                </ItemDescription>
                                <ItemDescription className="line-clamp-none text-copy-14 text-ink-muted">
                                    <AddressLines text={address.formatted} />
                                </ItemDescription>
                            </ItemContent>
                        </Item>
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * One thing a staff member may do to this account, with the reason it asks for: a Card whose
 * button opens the confirmation (never red: it only opens), and the confirmation itself, whose
 * button repeats the action's own name rather than "Confirm" (Geist's writing rules).
 *
 * Confirmed in a dialog rather than the browser's own: a window.confirm cannot be driven by a test,
 * which is how the panel's media delete went a whole step untested.
 */
function Action({ name, url, title, body, destructive = false }: { name: string; url: string; title: string; body: string; destructive?: boolean }) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });
    // Back to the button that opened it: neither dialog has a Radix Trigger to return to.
    const returnFocus = useReturnFocus(open);

    function close() {
        setOpen(false);
        form.reset();
        form.clearErrors();
    }

    function send() {
        form.post(url, { preserveScroll: true, onSuccess: () => setOpen(false) });
    }

    const reason = (autoFocus: boolean) => (
        <TextField
            id={`reason-${name}`}
            label={t('access::customers.reason')}
            helper={t('access::customers.reason_hint')}
            error={form.errors.reason}
            required
            autoFocus={autoFocus}
            value={form.data.reason}
            onChange={(event) => form.setData('reason', event.target.value)}
        />
    );

    return (
        <Card className="material-base gap-3 border-0 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-heading-14 text-ink">
                    <h3>{title}</h3>
                </CardTitle>
                <CardDescription className="text-copy-13 text-ink-muted">{body}</CardDescription>
            </CardHeader>
            <CardFooter className="px-4">
                <Button variant="outline" size="sm" data-test={name} onClick={() => setOpen(true)}>
                    {/* It opens a dialog, so it ends in "…" (the writing rules, frontend.md §1.10). */}
                    {`${title}…`}
                </Button>
            </CardFooter>

            {destructive ? (
                // Starts on Cancel, an outside click does not dismiss it, and there is no form for
                // Enter to send: only the named, red button confirms (Geist's Modal).
                <AlertDialog open={open} onOpenChange={(next) => (form.processing ? undefined : next ? setOpen(true) : close())}>
                    <AlertDialogContent onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 data-[size=default]:sm:max-w-md">
                        <div className="grid gap-4 p-6">
                            <AlertDialogHeader>
                                <AlertDialogTitle className="text-heading-20 text-ink">{title}</AlertDialogTitle>
                                <AlertDialogDescription className="text-copy-14 text-ink-muted">{body}</AlertDialogDescription>
                            </AlertDialogHeader>
                            {/* A refusal keeps the dialog open, so it is said here, where the person is
                                looking - this dialog's own, never one left over from another action. */}
                            <DialogError open={open} />
                            {reason(false)}
                        </div>
                        <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                            <AlertDialogCancel disabled={form.processing} data-test="modal-cancel">
                                {t('ui.cancel')}
                            </AlertDialogCancel>
                            <ActionButton variant="destructive" loading={form.processing} onClick={send} data-test={`confirm-${name}`}>
                                {title}
                            </ActionButton>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            ) : (
                <Dialog open={open} onOpenChange={(next) => (form.processing ? undefined : next ? setOpen(true) : close())}>
                    <DialogContent showCloseButton={false} onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 sm:max-w-md">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                send();
                            }}
                        >
                            <div className="grid gap-4 p-6">
                                <DialogHeader>
                                    <DialogTitle className="text-heading-20 text-ink">{title}</DialogTitle>
                                    <DialogDescription className="text-copy-14 text-ink-muted">{body}</DialogDescription>
                                </DialogHeader>
                                <DialogError open={open} />
                                {reason(true)}
                            </div>
                            <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                                <Button type="button" variant="outline" disabled={form.processing} onClick={close} data-test="modal-cancel">
                                    {t('ui.cancel')}
                                </Button>
                                <ActionButton type="submit" loading={form.processing} data-test={`confirm-${name}`}>
                                    {title}
                                </ActionButton>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            )}
        </Card>
    );
}
