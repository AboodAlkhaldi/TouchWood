import { lazy, Suspense, useRef, useState } from 'react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CarrierPage } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import { C, CarrierLogo, CarrierSwitch, DeleteCarrierDialog, LIST, trackingExample, useCarrierName, useOtherName } from './parts';

const CarrierDialog = lazy(() => import('./CarrierDialog').then((module) => ({ default: module.CarrierDialog })));

/*
| C3 · A carrier (shipping.md §4.1 part 1, Q4): the trail back to Carriers, its logo, names, country and
| switch, Edit and Delete for whoever may manage (Q12), and its details. Part 2 fills the rest with its
| price groups; until then a note says they come next.
*/

export default function Show({ carrier, countryName, mayManage, storeCode }: CarrierPage) {
    const t = useTranslator();
    const nameOf = useCarrierName();
    const otherOf = useOtherName();
    const [dialog, setDialog] = useState<'edit' | 'delete' | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const name = nameOf(carrier);
    const other = otherOf(carrier);
    const example = carrier.trackingPattern === null ? null : trackingExample(carrier.trackingPattern);
    const list = `${LIST}?store=${encodeURIComponent(storeCode)}`;
    const close = (open: boolean) => (open ? undefined : setDialog(null));

    return (
        <AdminLayout
            title={name}
            subtitle={countryName}
            breadcrumbs={[{ label: t(`${C}.title`), href: list }]}
            action={
                mayManage ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <ActionButton
                            type="button"
                            variant="outline"
                            onClick={(event) => {
                                opener.current = event.currentTarget;
                                setDialog('edit');
                            }}
                            data-test="edit-carrier"
                        >
                            {t(`${C}.edit`)}
                        </ActionButton>
                        <ActionButton
                            type="button"
                            variant="outline"
                            className="text-destructive"
                            disabledReason={carrier.active ? t(`${C}.delete_reason_on`) : undefined}
                            onClick={(event) => {
                                opener.current = event.currentTarget;
                                setDialog('delete');
                            }}
                            data-test="delete-carrier"
                        >
                            {t(`${C}.delete`)}
                        </ActionButton>
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-4">
                <FormError />

                <Card className="material-base">
                    <CardHeader className="flex flex-row flex-wrap items-center gap-3">
                        <CarrierLogo carrier={carrier} name={name} size="size-12" />
                        <div className="grid min-w-0 flex-1 gap-0.5">
                            <CardTitle className="flex flex-wrap items-center gap-2 text-heading-16 text-ink">
                                {name}
                                {carrier.active ? null : (
                                    <Badge className={tone('gray-subtle')} data-test="carrier-off">
                                        {t(`${C}.off`)}
                                    </Badge>
                                )}
                            </CardTitle>
                            {other !== '' && other !== name ? <span className="text-copy-13 text-ink-muted">{other}</span> : null}
                        </div>
                        {mayManage ? <CarrierSwitch carrier={carrier} name={name} storeCode={storeCode} /> : null}
                    </CardHeader>
                    <CardContent>
                        <h2 className="sr-only">{t(`${C}.page.details`)}</h2>
                        <dl className="grid gap-3 text-copy-14 sm:grid-cols-[max-content_1fr] sm:gap-x-6">
                            <dt className="text-ink-muted">{t(`${C}.page.phone`)}</dt>
                            <dd data-test="carrier-phone">
                                {carrier.phone !== null ? (
                                    <bdi dir="ltr" className="tw-figure">
                                        {carrier.phone}
                                    </bdi>
                                ) : (
                                    t(`${C}.page.none`)
                                )}
                            </dd>
                            <dt className="text-ink-muted">{t(`${C}.page.website`)}</dt>
                            <dd className="min-w-0 break-all" data-test="carrier-website">
                                {carrier.website !== null ? (
                                    <a href={carrier.website} target="_blank" rel="noreferrer" dir="ltr" className="text-ink underline-offset-4 hover:underline">
                                        {carrier.website}
                                    </a>
                                ) : (
                                    t(`${C}.page.none`)
                                )}
                            </dd>
                            <dt className="text-ink-muted">{t(`${C}.page.tracking`)}</dt>
                            <dd className="min-w-0 break-all" data-test="carrier-tracking">
                                {carrier.trackingPattern !== null ? (
                                    <>
                                        <bdi dir="ltr">{carrier.trackingPattern}</bdi>
                                        {example !== null ? <span className="block text-ink-muted">{t(`${C}.tracking.example`, { url: example })}</span> : null}
                                    </>
                                ) : (
                                    t(`${C}.page.none`)
                                )}
                            </dd>
                        </dl>
                    </CardContent>
                </Card>

                <Note data-test="prices-next">{t(`${C}.page.prices_next`)}</Note>
            </div>

            {dialog === 'edit' ? (
                <Suspense fallback={null}>
                    <CarrierDialog carrier={carrier} countryCode={carrier.countryCode} countryName={countryName} storeCode={storeCode} open onOpenChange={close} returnFocusTo={opener} />
                </Suspense>
            ) : null}
            {dialog === 'delete' ? <DeleteCarrierDialog carrier={carrier} name={name} storeCode={storeCode} fromPage open onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}
