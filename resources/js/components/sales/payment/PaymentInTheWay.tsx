import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Note } from '@/components/Note';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CheckoutTryData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { CHANNEL, P, minutesAndSeconds, postJson, untilOf, useClock, useCsrfToken, useServerNow } from './flow';

/*
| A try still live when the customer comes back, on the cart and on checkout alike (payments.md §4.2, Q8), and a second
| window while another is paying (§1.4 step 9, Q9). No form either way. It keeps asking, so the page comes back by
| itself with no reload once the try is over; a paid try goes to its order.
*/

const EVERY = 15000;

export default function PaymentInTheWay({ payment, onClosed }: { payment: CheckoutTryData; onClosed: (view: CheckoutTryData) => void }) {
    const t = useTranslator();
    const link = useLink();
    const token = useCsrfToken();
    const clock = useClock();
    const now = useServerNow(payment.serverNow);
    const [otherWindow, setOtherWindow] = useState(false);
    const until = untilOf(payment);

    // A window that is paying answers; none answering means this customer is simply back.
    useEffect(() => {
        if (typeof BroadcastChannel === 'undefined') {
            return undefined;
        }

        const channel = new BroadcastChannel(CHANNEL);
        channel.onmessage = (event) => {
            if (event.data === 'here') {
                setOtherWindow(true);
            }
        };
        channel.postMessage('ping');

        return () => channel.close();
    }, []);

    useEffect(() => {
        let gone = false;
        let timer: ReturnType<typeof setTimeout>;
        const ask = async () => {
            const answer = await postJson<CheckoutTryData>(link('storefront.sales.payment.follow', { try: payment.tryId }), token);

            if (gone) {
                return;
            }

            if (answer.ok && answer.data.state === 'LIVE') {
                timer = setTimeout(() => void ask(), EVERY);
            } else if (answer.ok && answer.data.mainStoreUrl !== null && answer.data.firstSeen) {
                onClosed(answer.data);
            } else if (answer.ok && answer.data.orderNumber !== null && answer.data.mainStoreUrl === null) {
                router.visit(link('storefront.sales.placed', { number: answer.data.orderNumber }));
            } else if (answer.ok || answer.status === 404 || answer.status === 401 || answer.status === 419) {
                // Over, or no longer this page's to ask. Asking again covers a reload that never landed; one that does ends this note.
                router.reload();
                timer = setTimeout(() => void ask(), EVERY);
            } else {
                timer = setTimeout(() => void ask(), EVERY);
            }
        };

        // Opening the page doesn't ask the gateway: only this does, so a try past its limit ends here.
        void ask();

        return () => {
            gone = true;
            clearTimeout(timer);
        };
        // Follows one try for as long as this note stands.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payment.tryId]);

    return (
        <Note data-test="payment-in-the-way">
            {otherWindow ? (
                t(`${P}.other_window`)
            ) : (
                <>
                    {until === null ? null : t(`${P}.in_the_way`, { time: clock(until) })}
                    {until === null ? null : (
                        <span className="tw-figure ms-2 text-label-14" dir="ltr" data-test="payment-time-left">
                            {minutesAndSeconds(new Date(until).getTime() - now)}
                        </span>
                    )}
                </>
            )}
        </Note>
    );
}
