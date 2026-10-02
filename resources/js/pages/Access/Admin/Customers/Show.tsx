import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { DialogError, FormError } from '@/components/FormError';
import { Badge, Button, Description, EmptyState, Input, Modal, ModalCancel } from '@/components/geist';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import type {
    AddressRow,
    CustomerAddressGroup,
    CustomerDetailsPage,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

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
| In Geist's parts (frontend.md 1.10): the facts are a Description, the statuses Badges, and each
| action is confirmed in a Modal that holds its reason - destructive for blocking and closing, whose
| confirm button is red, plain for the two that undo them.
*/

type Props = CustomerDetailsPage;

export default function Show({
    customer,
    communicationLocale,
    addresses,
    mayBlock,
    mayUnblock,
    mayStartDeletion,
    mayCancelDeletion,
    deletionDays,
}: Props) {
    const t = useTranslator();

    return (
        <AdminLayout title={customer.name} subtitle={t('access::customers.title')}>
            <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="grid content-start gap-6">
                    <section className="material-base grid gap-4 p-6">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="grid gap-1">
                                <h2 className="text-heading-16 text-ink">{customer.name}</h2>
                                <bdi dir="ltr" className="text-copy-14 text-ink-muted">
                                    {customer.email}
                                </bdi>
                            </div>

                            <Status
                                status={customer.status}
                                anonymized={customer.anonymized}
                                closingOn={customer.deletionScheduledFor}
                            />
                        </div>

                        <Description
                            items={[
                                {
                                    title: t('access::customers.type'),
                                    content: t(`access::customers.account_type.${customer.accountType}`),
                                },
                                { title: t('access::customers.home_store'), content: customer.homeStore },
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
                                {
                                    title: t('access::customers.communication_language'),
                                    content: t(`access::account.language.${communicationLocale}`),
                                },
                                {
                                    title: t('access::customers.email_verified'),
                                    content: customer.emailVerified
                                        ? t('access::customers.verified')
                                        : t('access::customers.not_verified'),
                                },
                                {
                                    title: t('access::customers.phone_verified'),
                                    content: customer.phoneVerified
                                        ? t('access::customers.verified')
                                        : t('access::customers.not_verified'),
                                },
                                { title: t('access::customers.registered'), content: isolate(customer.registeredAt) },
                            ]}
                        />

                        <p className="text-copy-13 text-ink-muted">{t('access::customers.profile_is_theirs')}</p>
                    </section>

                    <section className="grid gap-3">
                        <h2 className="text-heading-16 text-ink">{t('access::customers.addresses')}</h2>

                        {addresses.length === 0 ? (
                            <EmptyState
                                title={t('access::customers.no_addresses_title')}
                                description={t('access::customers.no_addresses')}
                            />
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
                            danger
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
                            danger
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

function Status({
    status,
    anonymized,
    closingOn,
}: {
    status: string;
    anonymized: boolean;
    closingOn: string | null;
}) {
    const t = useTranslator();

    if (anonymized) {
        return <Badge variant="gray-subtle">{t('access::customers.anonymized')}</Badge>;
    }

    return (
        <span className="flex flex-wrap gap-2">
            <Badge variant={status === 'BLOCKED' ? 'red-subtle' : 'green-subtle'}>
                {t(`access::customers.account_status.${status}`)}
            </Badge>

            {closingOn === null ? null : (
                <Badge variant="amber-subtle" data-test="deletion-pending">
                    {t('access::customers.deletion_pending', { date: isolate(closingOn) })}
                </Badge>
            )}
        </span>
    );
}

/** A customer's addresses in one store, exactly as that store lays them out. */
function Addresses({ group }: { group: CustomerAddressGroup }) {
    return (
        <div className="grid gap-2">
            <h3 className="text-heading-14 text-ink">{group.storeName}</h3>

            <ul className="grid gap-2 sm:grid-cols-2">
                {group.addresses.map((address: AddressRow) => (
                    <li key={address.id} className="material-base grid gap-0.5 p-4">
                        <p className="text-label-14 font-medium text-ink">{address.label}</p>
                        <p className="text-copy-14 text-ink-muted">{address.recipientName}</p>
                        <p className="tw-figure text-copy-14 text-ink-muted" dir="ltr">
                            {address.phone}
                        </p>
                        <p className="whitespace-pre-line text-copy-14 text-ink-muted">{address.formatted}</p>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * One thing a staff member may do to this account, with the reason it asks for.
 *
 * Confirmed in a Geist Modal rather than in the browser's own dialog: a window.confirm cannot be
 * driven by a test, which is how the panel's media delete went a whole step untested. The reason
 * field takes the focus when the dialog opens, because nothing can happen until it is written; the
 * confirm button repeats the action's own name rather than saying "Confirm" (Geist's writing
 * rules), and it sits outside the form, so it names the form it submits.
 */
function Action({
    name,
    url,
    title,
    body,
    danger = false,
}: {
    name: string;
    url: string;
    title: string;
    body: string;
    danger?: boolean;
}) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });
    const formId = `action-${name}`;

    function close() {
        setOpen(false);
        form.reset();
    }

    return (
        <section className="material-base grid gap-2 p-4">
            <h3 className="text-heading-14 text-ink">{title}</h3>
            <p className="text-copy-13 text-ink-muted">{body}</p>

            <Button
                type={danger ? 'error' : 'secondary'}
                size="small"
                className="w-fit"
                data-test={name}
                onClick={() => setOpen(true)}
            >
                {title}
            </Button>

            <Modal
                open={open}
                onOpenChange={(next) => (next ? setOpen(true) : close())}
                destructive={danger}
                title={title}
                description={body}
                actions={
                    <>
                        <ModalCancel onClick={close} disabled={form.processing} />
                        <Button
                            type={danger ? 'error' : 'default'}
                            typeName="submit"
                            form={formId}
                            loading={form.processing}
                            data-test={`confirm-${name}`}
                        >
                            {title}
                        </Button>
                    </>
                }
            >
                <form
                    id={formId}
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(url, { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                    className="grid gap-3"
                >
                    {/* A refusal keeps the dialog open, so it is said here, where the person is
                        looking - this dialog's own, never one left over from another action. */}
                    <DialogError open={open} />

                    <Input
                        id={`reason-${name}`}
                        label={t('access::customers.reason')}
                        helper={t('access::customers.reason_hint')}
                        error={form.errors.reason}
                        required
                        autoFocus
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                    />
                </form>
            </Modal>
        </section>
    );
}
