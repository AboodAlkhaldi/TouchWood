import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import type { CheckoutTryData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { P } from './flow';

/** The store switched off while paying: one page, once, with a way to the main store (payments.md §1.10). */
export default function ClosedStore({ view }: { view: CheckoutTryData }) {
    const t = useTranslator();
    const words = view.orderNumber !== null ? t(`${P}.closed.paid`, { number: isolate(view.orderNumber) }) : view.nothingCharged || view.declineCode !== null ? t(`${P}.closed.nothing_taken`) : t(`${P}.closed.not_completed`);

    return (
        <StorefrontLayout title={t(`${P}.closed.title`)}>
            <Card className="material-base mx-auto w-full max-w-xl gap-3 border-0 py-6" data-test="payment-store-closed">
                <CardContent className="grid justify-items-center gap-3 px-6 text-center">
                    <h1 className="text-heading-24 text-ink">{t(`${P}.closed.title`)}</h1>
                    <p className="text-copy-14 text-ink">{words}</p>
                    {view.mainStoreUrl === null ? null : (
                        <Button asChild>
                            <a href={view.mainStoreUrl} data-test="payment-main-store">
                                {t(`${P}.closed.main`)}
                            </a>
                        </Button>
                    )}
                </CardContent>
            </Card>
        </StorefrontLayout>
    );
}
