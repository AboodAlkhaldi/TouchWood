import { CopyButton } from '@/components/geist-only/CopyButton';
import { useMoments } from '@/components/Time';
import { Amount, useDiscountWords, useLocale } from '@/components/promotions/parts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { AccountLayout } from '@/layouts/AccountLayout';
import { isolate } from '@/lib/bidi';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { MyCouponData, MyCouponsPage } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M5 - the customer's own coupons in this store (promotions.md §4.1), a page of their account beside its
| own tabs, always offered (owner's Q12). One card per coupon, soonest to end first: the code, large,
| with the panel's own Copy Button (Q13); what it takes off; the minimum order; until when; the uses
| left. Whether a coupon applies to a cart is checked at checkout, as the line under the list says.
*/

export default function Coupons({ coupons }: MyCouponsPage) {
    const t = useTranslator();

    return (
        <AccountLayout title={t('promotions::shop_coupons.title')} subtitle={t('promotions::shop_coupons.subtitle')} page="promotions.coupons">
            <div className="grid min-w-0 content-start gap-4">
                {coupons.length === 0 ? (
                    <Empty className="material-base" data-test="coupons-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::shop_coupons.empty')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <ul className="grid gap-4 sm:grid-cols-2" data-test="my-coupons">
                            {coupons.map((coupon) => (
                                <li key={coupon.code}>
                                    <CouponCard coupon={coupon} />
                                </li>
                            ))}
                        </ul>
                        <p className="text-copy-13 text-ink-muted">{t('promotions::shop_coupons.how')}</p>
                    </>
                )}
            </div>
        </AccountLayout>
    );
}

function CouponCard({ coupon }: { coupon: MyCouponData }) {
    const t = useTranslator();
    const locale = useLocale();
    const discount = useDiscountWords();
    const moments = useMoments();

    return (
        <Card className="h-full gap-3 py-5" data-test={`my-coupon-${coupon.code}`}>
            <CardHeader className="px-5">
                <CardTitle className="flex items-center justify-between gap-2">
                    <bdi dir="ltr" className="font-mono text-heading-24 text-ink">
                        {coupon.code}
                    </bdi>
                    <CopyButton text={coupon.code} label={t('promotions::shop_coupons.copy')} />
                </CardTitle>
                <p className="text-label-14 text-ink">{coupon.name}</p>
            </CardHeader>
            <CardContent className="grid gap-1 px-5 text-copy-14 text-ink-muted">
                <p className="text-label-14 text-ink">{discount(coupon.discountKind, coupon.percent, coupon.amount, coupon.cap)}</p>
                {coupon.minimum === null ? null : (
                    <p>
                        {t('promotions::shop_coupons.minimum')} <Amount value={coupon.minimum} />
                    </p>
                )}
                <p>
                    {coupon.endsAt === null ? t('promotions::shop_coupons.no_end') : t('promotions::shop_coupons.ends', { date: isolate(moments.date(coupon.endsAt)) })}
                </p>
                {coupon.usesLeft === null ? null : <p>{t('promotions::shop_coupons.uses_left', { count: figure(locale, coupon.usesLeft) })}</p>}
            </CardContent>
        </Card>
    );
}
