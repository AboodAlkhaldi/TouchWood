import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
*/

type Props = CustomerDetailsPage;

export default function Show({
    customer,
    locale,
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
                <div className="grid gap-6">
                    <section className="grid gap-4 rounded-lg border border-line bg-surface p-6 shadow-card">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="grid gap-1">
                                <h2 className="text-base font-semibold text-ink">{customer.name}</h2>
                                <bdi dir="ltr" className="text-sm text-ink-muted">
                                    {customer.email}
                                </bdi>
                            </div>

                            <Status
                                status={customer.status}
                                anonymized={customer.anonymized}
                                closingOn={customer.deletionScheduledFor}
                            />
                        </div>

                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Fact label={t('access::customers.type')}>
                                {t(`access::customers.account_type.${customer.accountType}`)}
                            </Fact>

                            <Fact label={t('access::customers.home_store')}>{customer.homeStore}</Fact>

                            <Fact label={t('access::customers.phone')} ltr>
                                {customer.phone ?? t('access::customers.no_phone')}
                            </Fact>

                            <Fact label={t('access::customers.communication_language')}>
                                {t(`access::account.language.${locale}`)}
                            </Fact>

                            <Fact label={t('access::customers.email_verified')}>
                                {customer.emailVerified
                                    ? t('access::customers.verified')
                                    : t('access::customers.not_verified')}
                            </Fact>

                            <Fact label={t('access::customers.phone_verified')}>
                                {customer.phoneVerified
                                    ? t('access::customers.verified')
                                    : t('access::customers.not_verified')}
                            </Fact>

                            <Fact label={t('access::customers.registered')}>
                                {isolate(customer.registeredAt)}
                            </Fact>
                        </dl>

                        <p className="text-xs text-ink-muted">
                            {t('access::customers.profile_is_theirs')}
                        </p>
                    </section>

                    <section className="grid gap-4 rounded-lg border border-line bg-surface p-6 shadow-card">
                        <h2 className="text-base font-semibold text-ink">
                            {t('access::customers.addresses')}
                        </h2>

                        {addresses.length === 0 ? (
                            <p className="text-sm text-ink-muted">
                                {t('access::customers.no_addresses')}
                            </p>
                        ) : (
                            addresses.map((group) => <Addresses key={group.storeId} group={group} />)
                        )}
                    </section>
                </div>

                <aside className="grid gap-4">
                    <h2 className="text-xs font-semibold tracking-wide text-ink-muted uppercase">
                        {t('access::customers.actions')}
                    </h2>

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

function Fact({ label, children, ltr = false }: { label: string; children: string; ltr?: boolean }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-ink-muted">{label}</dt>
            <dd className="text-sm text-ink">
                {ltr ? (
                    <bdi dir="ltr" className="tw-figure">
                        {children}
                    </bdi>
                ) : (
                    children
                )}
            </dd>
        </div>
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
        return (
            <span className="rounded-md bg-surface-sunken px-2 py-1 text-xs text-ink-muted">
                {t('access::customers.anonymized')}
            </span>
        );
    }

    return (
        <span className="flex flex-wrap gap-2">
            <span
                className={[
                    'rounded-md px-2 py-1 text-xs',
                    status === 'BLOCKED' ? 'bg-bad-soft text-bad' : 'bg-good-soft text-good',
                ].join(' ')}
            >
                {t(`access::customers.account_status.${status}`)}
            </span>

            {closingOn === null ? null : (
                <span
                    data-test="deletion-pending"
                    className="rounded-md bg-warn-soft px-2 py-1 text-xs text-warn"
                >
                    {t('access::customers.deletion_pending', { date: isolate(closingOn) })}
                </span>
            )}
        </span>
    );
}

/** A customer's addresses in one store, exactly as that store lays them out. */
function Addresses({ group }: { group: CustomerAddressGroup }) {
    return (
        <div className="grid gap-2">
            <h3 className="text-sm font-medium text-ink">{group.storeName}</h3>

            <ul className="grid gap-2">
                {group.addresses.map((address: AddressRow) => (
                    <li key={address.id} className="rounded-md border border-line p-3">
                        <p className="text-sm font-medium text-ink">{address.label}</p>
                        <p className="text-sm text-ink-muted">{address.recipientName}</p>
                        <p className="tw-figure text-sm text-ink-muted" dir="ltr">
                            {address.phone}
                        </p>
                        <p className="whitespace-pre-line text-sm text-ink-muted">
                            {address.formatted}
                        </p>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * One thing a staff member may do to this account, with the reason it asks for.
 *
 * Confirmed in the page rather than in the browser's own dialog: a window.confirm cannot be driven
 * by a test, which is how the panel's media delete went a whole step untested.
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

    return (
        <section
            className={[
                'grid gap-2 rounded-lg border p-4',
                danger ? 'border-bad/30 bg-bad-soft' : 'border-line bg-surface',
            ].join(' ')}
        >
            <p className={['text-sm font-medium', danger ? 'text-bad' : 'text-ink'].join(' ')}>
                {title}
            </p>
            <p className="text-xs text-ink-muted">{body}</p>

            {open ? (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(url, { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                    className="grid gap-3"
                >
                    <Field
                        id={`reason-${name}`}
                        label={t('access::customers.reason')}
                        hint={t('access::customers.reason_hint')}
                        error={form.errors.reason}
                    >
                        <Input
                            id={`reason-${name}`}
                            required
                            autoFocus
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                        />
                    </Field>

                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={form.processing}
                            data-test={`confirm-${name}`}
                        >
                            {t('access::customers.confirm')}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setOpen(false);
                                form.reset();
                            }}
                        >
                            {t('access::customers.cancel')}
                        </Button>
                    </div>
                </form>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-fit"
                    data-test={name}
                    onClick={() => setOpen(true)}
                >
                    {title}
                </Button>
            )}
        </section>
    );
}
