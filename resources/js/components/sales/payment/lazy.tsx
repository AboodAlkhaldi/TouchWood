import { lazy, useEffect } from 'react';
import { ActionButton } from '@/components/ActionButton';
import { Note } from '@/components/Note';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import type { CheckoutTryData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| Paying online's code, each part loaded only on a page that needs it (the 60 KB budget). A part can fail to load: the
| connection drops, or a new version was published while the page was open and the old file has gone. Nothing above it
| would catch that and the whole page would go blank, so each part says so in its own place and the page keeps working.
*/

const P = 'sales::shop_payment';

function FormUnavailable({ number, onState }: { number?: string; onState: (state: { ready: boolean; locked: boolean; failed: boolean }) => void }) {
    const t = useTranslator();

    // Pay stays out of reach, saying why.
    useEffect(() => onState({ ready: false, locked: false, failed: true }), [onState]);

    return (
        <div className="grid justify-items-start gap-2" data-test="payment-unavailable">
            <p className="text-copy-14 text-ink">{t(number === undefined ? `${P}.form_failed` : `${P}.request.form_failed`)}</p>
            {/* A file that didn't load isn't asked for again without one. */}
            <ActionButton variant="outline" onClick={() => window.location.reload()} data-test="payment-reload">
                {t(`${P}.try_again`)}
            </ActionButton>
        </div>
    );
}

function NoteUnavailable() {
    const t = useTranslator();

    return <Note data-test="payment-in-the-way">{t(`${P}.pay_later`)}</Note>;
}

function ClosedUnavailable({ view }: { view: CheckoutTryData }) {
    const t = useTranslator();

    return (
        <StorefrontLayout title={t(`${P}.closed.title`)}>
            <div className="grid justify-items-center gap-3 text-center" data-test="payment-store-closed">
                <h1 className="text-heading-24 text-ink">{t(`${P}.closed.title`)}</h1>
                {view.orderNumber === null ? null : <p className="text-copy-14 text-ink">{t(`${P}.closed.paid`, { number: isolate(view.orderNumber) })}</p>}
                {view.mainStoreUrl === null ? null : (
                    <a href={view.mainStoreUrl} className="text-copy-14 text-ink underline underline-offset-4">
                        {t(`${P}.closed.main`)}
                    </a>
                )}
            </div>
        </StorefrontLayout>
    );
}

export const PaymentBlock = lazy(() => import('./PaymentBlock').catch(() => ({ default: FormUnavailable })));
export const PaymentInTheWay = lazy(() => import('./PaymentInTheWay').catch(() => ({ default: NoteUnavailable })));
export const ClosedStore = lazy(() => import('./ClosedStore').catch(() => ({ default: ClosedUnavailable })));
