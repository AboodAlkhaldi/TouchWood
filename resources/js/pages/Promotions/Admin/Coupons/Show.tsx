import { useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { FormError, useFreshRefusal } from '@/components/FormError';
import { Note } from '@/components/Note';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Amount, CouponStateBadge, Unknown, useLocale } from '@/components/promotions/parts';
import { isolate } from '@/lib/bidi';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import { CouponForm } from '@/pages/Promotions/Admin/Coupons/CouponForm';
import type { AssignmentData, CouponFormData, CouponPage, CouponUseData, CustomerNameData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M2 - a coupon's page, or a new coupon's (promotions.md §4.1): Details, Uses and - for a coupon given
| to customers - Customers (owner's Q3); a new coupon has Details only. Uses and Customers are read only
| when their tab opens, a page at a time with Show More.
|
| A manager (Q16) has Turn Off / Turn On in the header, saved at once, and Delete Coupon… while it was
| never used - out of reach once used, the reason in its tooltip (Q6); Give to a Segment… on Customers
| (Q14) and Remove on each unused assignment.
*/

const LIST = '/admin/coupons';
type Tab = 'details' | 'uses' | 'customers';

export default function Show(page: CouponPage) {
    const t = useTranslator();
    const locale = useLocale();
    const coupon = page.coupon;
    const tabs: Tab[] = coupon === null ? ['details'] : coupon.eligibility === 'ASSIGNED' ? ['details', 'uses', 'customers'] : ['details', 'uses'];
    const tab: Tab = tabs.includes(page.tab as Tab) ? (page.tab as Tab) : 'details';
    // The code isolated: one that starts with digits keeps its order on an Arabic page.
    const title = coupon === null ? t('promotions::admin_coupons.show.new_title') : isolate(coupon.code);

    return (
        <AdminLayout
            title={title}
            subtitle={page.storeName}
            breadcrumbs={[{ label: t('promotions::admin_coupons.list.title'), href: `${LIST}?store=${encodeURIComponent(page.storeCode)}` }, { label: title }]}
            action={coupon === null || !page.mayManage ? undefined : <HeaderActions coupon={coupon} />}
        >
            <div className="grid gap-4">
                <FormError />
                {page.storeIsActive ? null : (
                    <div>
                        <StoreOffBadge />
                    </div>
                )}
                {coupon === null ? null : (
                    <div className="flex flex-wrap items-center gap-3 text-copy-14 text-ink-muted" data-test="coupon-facts">
                        <CouponStateBadge state={coupon.state} />
                        <span>{t('promotions::admin_coupons.show.used', { count: figure(locale, coupon.usesTotal) })}</span>
                    </div>
                )}

                {tabs.length === 1 ? (
                    <CouponForm page={page} />
                ) : (
                    // Each tab is read from the server: the arrows move between them, Enter or Space opens one (as
                    // Products' tabs). The page keeps its state, so the focus stays on the tab and Details keeps
                    // what was typed while another tab is read.
                    <Tabs value={tab} activationMode="manual" onValueChange={(next) => router.get(`${LIST}/${coupon?.id}`, { tab: next }, { preserveScroll: true, preserveState: true, replace: true })}>
                        <TabsList variant="line" className="h-10 w-full max-w-full min-w-0 justify-start overflow-x-auto border-b border-line p-0" aria-label={t('promotions::admin_coupons.show.tabs_label')}>
                            {tabs.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`tab-${each}`} className="flex-none px-3 text-label-14">
                                    {t(`promotions::admin_coupons.show.tab_${each}`)}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        <TabsContent value="details" forceMount className="pt-4 data-[state=inactive]:hidden">
                            <CouponForm page={page} />
                        </TabsContent>
                        <TabsContent value="uses" className="pt-4">
                            {tab === 'uses' && coupon !== null ? <Uses page={page} coupon={coupon} /> : null}
                        </TabsContent>
                        <TabsContent value="customers" className="pt-4">
                            {tab === 'customers' && coupon !== null ? <Customers page={page} coupon={coupon} /> : null}
                        </TabsContent>
                    </Tabs>
                )}
            </div>
        </AdminLayout>
    );
}

function HeaderActions({ coupon }: { coupon: CouponFormData }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const remove = useForm({});
    const deleteButton = useRef<HTMLButtonElement>(null);
    const used = coupon.usesTotal > 0;
    // Only the delete's own refusal, never an older one (useFreshRefusal).
    const refusal = useFreshRefusal(deleting);

    return (
        <div className="flex flex-wrap gap-2">
            <ActionButton
                type="button"
                variant="outline"
                loading={busy}
                data-test="switch-coupon"
                onClick={() => router.post(`${LIST}/${coupon.id}/switch`, { active: !coupon.active }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
            >
                {coupon.active ? t('promotions::admin_coupons.show.turn_off') : t('promotions::admin_coupons.show.turn_on')}
            </ActionButton>
            <ActionButton ref={deleteButton} type="button" variant="outline" className="text-bad" disabledReason={used ? t('promotions::admin_coupons.show.delete_used') : undefined} onClick={() => setDeleting(true)} data-test="delete-coupon">
                {`${t('promotions::admin_coupons.show.delete')}…`}
            </ActionButton>
            <DestructiveActionDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={t('promotions::admin_coupons.show.delete_title')}
                confirmLabel={t('promotions::admin_coupons.show.delete_title')}
                description={t('promotions::admin_coupons.show.delete_body', { code: isolate(coupon.code) })}
                irreversibleDescription={t('promotions::admin_coupons.show.delete_irreversible', { code: isolate(coupon.code) })}
                verificationPhrase={coupon.code}
                verificationLabel={t('promotions::admin_coupons.show.verification_label')}
                loading={remove.processing}
                error={refusal}
                onConfirm={() => remove.post(`${LIST}/${coupon.id}/delete`, { onSuccess: () => setDeleting(false) })}
                returnFocusTo={deleteButton}
            />
        </div>
    );
}

function Name({ customer }: { customer: CustomerNameData }) {
    const t = useTranslator();

    return customer.deleted ? <span className="text-ink-muted">{t('promotions::admin_coupons.show.deleted_customer')}</span> : <>{customer.name}</>;
}

function Uses({ page, coupon }: { page: CouponPage; coupon: CouponFormData }) {
    const t = useTranslator();
    const list = useLoadMore(page.uses, (use: CouponUseData) => String(use.id));

    if (list.rows.length === 0) {
        return (
            <Empty className="material-base" data-test="uses-empty">
                <EmptyHeader>
                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::admin_coupons.show.no_uses')}</EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <div className="grid gap-4">
            <div className="material-base max-w-full overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{t('promotions::admin_coupons.show.tab_uses')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            <TableHead>{t('promotions::admin_coupons.show.col_date')}</TableHead>
                            <TableHead>{t('promotions::admin_coupons.show.col_order')}</TableHead>
                            <TableHead>{t('promotions::admin_coupons.show.col_customer')}</TableHead>
                            <TableHead className="text-end">{t('promotions::admin_coupons.show.col_discount')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {list.rows.map((use) => (
                            <TableRow key={use.id} data-test={`use-${use.id}`}>
                                <TableCell className="whitespace-nowrap text-copy-13 text-ink-muted">
                                    <Time value={use.usedAt} />
                                </TableCell>
                                <TableCell>
                                    <bdi dir="ltr" className="font-mono">
                                        {use.orderNumber}
                                    </bdi>
                                </TableCell>
                                <TableCell>
                                    <Name customer={use.customer} />
                                </TableCell>
                                <TableCell className="text-end">
                                    <Amount value={use.discount} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {page.usesNextAt !== null && page.usesNextId !== null ? (
                <LoadMoreButton loading={list.loading} onClick={() => list.more(`${LIST}/${coupon.id}`, { tab: 'uses', uses_before_at: page.usesNextAt ?? '', uses_before_id: String(page.usesNextId) }, ['uses', 'usesNextAt', 'usesNextId'])} />
            ) : null}
        </div>
    );
}

function Customers({ page, coupon }: { page: CouponPage; coupon: CouponFormData }) {
    const t = useTranslator();
    const locale = useLocale();
    const list = useLoadMore(page.customers, (row: AssignmentData) => row.customer.id);
    const [giving, setGiving] = useState(false);
    const [removing, setRemoving] = useState<AssignmentData | null>(null);
    const [busy, setBusy] = useState(false);
    // Where the focus goes once a removed customer's own button is gone with its row.
    const giveButton = useRef<HTMLButtonElement>(null);
    const removeRefusal = useFreshRefusal(removing !== null);

    return (
        <div className="grid gap-4">
            {page.mayManage ? (
                <div className="flex justify-end">
                    <Button ref={giveButton} type="button" variant="outline" onClick={() => setGiving(true)} data-test="give-segment">
                        {`${t('promotions::admin_coupons.give.open')}…`}
                    </Button>
                </div>
            ) : null}

            {list.rows.length === 0 ? (
                <Empty className="material-base" data-test="customers-empty">
                    <EmptyHeader>
                        <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::admin_coupons.show.no_customers')}</EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="material-base max-w-full overflow-x-auto">
                    <Table>
                        <TableCaption className="sr-only">{t('promotions::admin_coupons.show.tab_customers')}</TableCaption>
                        <TableHeader className="bg-surface-sunken">
                            <TableRow>
                                <TableHead>{t('promotions::admin_coupons.show.col_customer')}</TableHead>
                                <TableHead>{t('promotions::admin_coupons.show.col_given')}</TableHead>
                                <TableHead>{t('promotions::admin_coupons.show.col_segment')}</TableHead>
                                <TableHead className="text-end">{t('promotions::admin_coupons.show.col_uses')}</TableHead>
                                {page.mayManage ? <TableHead className="text-end">{t('promotions::admin_coupons.show.col_actions')}</TableHead> : null}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {list.rows.map((row) => (
                                <TableRow key={row.customer.id} data-test={`assignment-${row.customer.id}`}>
                                    <TableCell>
                                        <Name customer={row.customer} />
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-copy-13 text-ink-muted">
                                        <Time value={row.assignedAt} />
                                    </TableCell>
                                    <TableCell>{row.segment ?? <Unknown />}</TableCell>
                                    <TableCell className="text-end">
                                        <bdi dir="ltr" className="tw-figure">
                                            {figure(locale, row.uses)}
                                        </bdi>
                                    </TableCell>
                                    {page.mayManage ? (
                                        <TableCell className="text-end">
                                            <ActionButton type="button" size="sm" variant="outline" disabledReason={row.uses > 0 ? t('promotions::admin_coupons.show.remove_used') : undefined} onClick={() => setRemoving(row)} data-test={`remove-${row.customer.id}`}>
                                                {`${t('promotions::admin_coupons.show.remove')}…`}
                                            </ActionButton>
                                        </TableCell>
                                    ) : null}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            {page.customersNextAt !== null && page.customersNextId !== null ? (
                <LoadMoreButton loading={list.loading} onClick={() => list.more(`${LIST}/${coupon.id}`, { tab: 'customers', customers_before_at: page.customersNextAt ?? '', customers_before_id: page.customersNextId ?? '' }, ['customers', 'customersNextAt', 'customersNextId'])} />
            ) : null}

            <GiveDialog open={giving} onOpenChange={setGiving} page={page} coupon={coupon} />

            <PanelDialog
                open={removing !== null}
                onOpenChange={(open) => (open ? undefined : setRemoving(null))}
                destructive
                title={t('promotions::admin_coupons.show.remove_title')}
                description={t('promotions::admin_coupons.show.remove_body', { name: isolate(removing?.customer.name ?? t('promotions::admin_coupons.show.deleted_customer')), code: isolate(coupon.code) })}
                busy={busy}
                returnFocusTo={giveButton}
                confirm={
                    <ActionButton
                        type="button"
                        variant="destructive"
                        loading={busy}
                        data-test="confirm-remove"
                        onClick={() =>
                            removing === null
                                ? undefined
                                : router.post(`${LIST}/${coupon.id}/assignments/${removing.customer.id}/remove`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setRemoving(null) })
                        }
                    >
                        {t('promotions::admin_coupons.show.remove')}
                    </ActionButton>
                }
            >
                {removeRefusal === undefined ? null : (
                    <Note variant="error" alert>
                        {removeRefusal}
                    </Note>
                )}
            </PanelDialog>
        </div>
    );
}

function GiveDialog({ open, onOpenChange, page, coupon }: { open: boolean; onOpenChange: (open: boolean) => void; page: CouponPage; coupon: CouponFormData }) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm({ segment: '' });
    const chosen = page.segments.find((segment) => segment.id === form.data.segment) ?? null;
    // A refusal that names no box - a segment gone meanwhile - is said here, not behind the dialog.
    const fresh = useFreshRefusal(open);
    const refusal = form.errors.segment ?? fresh;

    return (
        <PanelDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) form.reset();
                onOpenChange(next);
            }}
            title={t('promotions::admin_coupons.give.title')}
            description={t('promotions::admin_coupons.give.description', { code: isolate(coupon.code) })}
            busy={form.processing}
            confirm={
                <ActionButton
                    type="button"
                    loading={form.processing}
                    disabledReason={chosen === null ? t('promotions::admin_coupons.give.choose_first') : undefined}
                    data-test="confirm-give"
                    onClick={() => form.post(`${LIST}/${coupon.id}/assign`, { preserveScroll: true, onSuccess: () => onOpenChange(false) })}
                >
                    {t('promotions::admin_coupons.give.confirm')}
                </ActionButton>
            }
        >
            {page.segments.length === 0 ? (
                <p className="text-copy-14 text-ink-muted" data-test="no-segments">
                    {t('promotions::admin_coupons.give.none')}
                </p>
            ) : (
                <RadioGroup
                    value={form.data.segment}
                    onValueChange={(value) => form.setData('segment', value)}
                    className="max-h-72 gap-2 overflow-y-auto"
                    aria-label={t('promotions::admin_coupons.give.title')}
                    aria-describedby={refusal === undefined ? undefined : 'give-refusal'}
                    aria-invalid={refusal === undefined ? undefined : true}
                >
                    {page.segments.map((segment) => (
                        <Field key={segment.id} orientation="horizontal" className="gap-2">
                            <RadioGroupItem id={`segment-${segment.id}`} value={segment.id} data-test={`segment-${segment.id}`} />
                            <FieldLabel htmlFor={`segment-${segment.id}`} className="text-label-14 font-normal text-ink">
                                {segment.name}
                                <span className="block text-copy-13 text-ink-muted">{t('promotions::admin_coupons.give.members', { count: figure(locale, segment.memberCount) })}</span>
                            </FieldLabel>
                        </Field>
                    ))}
                </RadioGroup>
            )}
            {chosen === null ? null : (
                <p className="text-copy-13 text-ink-muted" data-test="give-effect">
                    {t('promotions::admin_coupons.give.effect', { count: figure(locale, chosen.memberCount) })}
                </p>
            )}
            {refusal === undefined ? null : (
                <p id="give-refusal" role="alert" className="text-copy-13 text-bad">
                    {refusal}
                </p>
            )}
        </PanelDialog>
    );
}
