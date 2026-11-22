import { useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { useFreshRefusal } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { PhoneCards } from '@/components/PhoneCards';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Amount, Unknown, useLocale } from '@/components/promotions/parts';
import { isolate } from '@/lib/bidi';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { AssignmentData, CouponFormData, CouponPage, CouponUseData, CustomerNameData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M2's Uses and Customers tabs (promotions.md §4.1; Show.tsx says the rest).
*/

const LIST = '/admin/coupons';

function Name({ customer }: { customer: CustomerNameData }) {
    const t = useTranslator();

    return customer.deleted ? <span className="text-ink-muted">{t('promotions::admin_coupons.show.deleted_customer')}</span> : <>{customer.name}</>;
}

export function Uses({ page, coupon }: { page: CouponPage; coupon: CouponFormData }) {
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
            <div className="material-base hidden max-w-full overflow-x-auto sm:block">
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
            {/* On a phone each use a card (round 3 #15); a use opens nothing, as its row. */}
            <PhoneCards
                test="use"
                cards={list.rows.map((use) => ({
                    id: String(use.id),
                    title: (
                        <bdi dir="ltr" className="font-mono">
                            {use.orderNumber}
                        </bdi>
                    ),
                    lines: [
                        <Time key="d" value={use.usedAt} focusable={false} />,
                        <span key="c">
                            <Name customer={use.customer} />
                        </span>,
                        <span key="a">
                            {`${t('promotions::admin_coupons.show.col_discount')}: `}
                            <Amount value={use.discount} />
                        </span>,
                    ],
                }))}
            />
            {page.usesNextAt !== null && page.usesNextId !== null ? (
                <LoadMoreButton loading={list.loading} onClick={() => list.more(`${LIST}/${coupon.id}`, { tab: 'uses', uses_before_at: page.usesNextAt ?? '', uses_before_id: String(page.usesNextId) }, ['uses', 'usesNextAt', 'usesNextId'])} />
            ) : null}
        </div>
    );
}

export function Customers({ page, coupon }: { page: CouponPage; coupon: CouponFormData }) {
    const t = useTranslator();
    const locale = useLocale();
    const list = useLoadMore(page.customers, (row: AssignmentData) => row.customer.id);
    const [giving, setGiving] = useState(false);
    const [removing, setRemoving] = useState<AssignmentData | null>(null);
    const [busy, setBusy] = useState(false);
    // Where the focus goes once a removed customer's own button is gone with its row.
    const giveButton = useRef<HTMLButtonElement>(null);
    const removeRefusal = useFreshRefusal(removing !== null);
    // A customer's Remove…, in their row and on their phone card under its own test name.
    const remove = (row: AssignmentData, test: string) => (
        <ActionButton type="button" size="sm" variant="outline" disabledReason={row.uses > 0 ? t('promotions::admin_coupons.show.remove_used') : undefined} onClick={() => setRemoving(row)} data-test={test}>
            {`${t('promotions::admin_coupons.show.remove')}…`}
        </ActionButton>
    );

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
                <>
                    <div className="material-base hidden max-w-full overflow-x-auto sm:block">
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
                                        {page.mayManage ? <TableCell className="text-end">{remove(row, `remove-${row.customer.id}`)}</TableCell> : null}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    {/* On a phone each customer given the coupon a card (round 3 #15), Remove… at its end. */}
                    <PhoneCards
                        test="assignment"
                        cards={list.rows.map((row) => ({
                            id: row.customer.id,
                            title: <Name customer={row.customer} />,
                            lines: [
                                <span key="g">
                                    {`${t('promotions::admin_coupons.show.col_given')}: `}
                                    <Time value={row.assignedAt} focusable={false} />
                                </span>,
                                <span key="s">
                                    {`${t('promotions::admin_coupons.show.col_segment')}: `}
                                    {row.segment ?? <Unknown />}
                                </span>,
                                <span key="u" className="tw-figure">{`${t('promotions::admin_coupons.show.col_uses')}: ${figure(locale, row.uses)}`}</span>,
                            ],
                            menu: page.mayManage ? remove(row, `remove-card-${row.customer.id}`) : undefined,
                        }))}
                    />
                </>
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
