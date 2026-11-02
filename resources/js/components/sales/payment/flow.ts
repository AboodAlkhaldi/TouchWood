import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useStoreZone } from '@/components/Time';
import { intlLocale } from '@/lib/digits';
import type { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import type { CheckoutTryData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| What paying online shares between the form and the note on coming back (payments.md §4.2): the JSON posts, the clock
| that trusts the server's time over the browser's, and the words a try's ending is told in.
*/

export const P = 'sales::shop_payment';

/** Windows that are paying answer here, so another window knows it is the second one (§1.4 step 9). */
export const CHANNEL = 'tw-payment';

export type Answer<T> = { ok: true; data: T } | { ok: false; status: number; type: string; detail: string };

export function useCsrfToken(): string {
    return (usePage<SharedProps>().props as unknown as { csrfToken: string }).csrfToken;
}

/** A post the open page reads the answer of. No answer at all has status 0: nobody knows what it did. */
export async function postJson<T>(path: string, token: string, body: Record<string, string> = {}): Promise<Answer<T>> {
    try {
        const response = await fetch(path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
            body: JSON.stringify(body),
        });

        if (response.ok) {
            return { ok: true, data: (await response.json()) as T };
        }

        const problem = (await response.json().catch(() => ({}))) as { type?: string; detail?: string };

        return { ok: false, status: response.status, type: problem.type ?? '', detail: problem.detail ?? '' };
    } catch {
        return { ok: false, status: 0, type: '', detail: '' };
    }
}

/** Now as the server has it, ticking each second. */
export function useServerNow(serverNow: string): number {
    const [skew] = useState(() => new Date(serverNow).getTime() - Date.now());
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(timer);
    }, []);

    return now + skew;
}

/** mm:ss in 0-9. */
export function minutesAndSeconds(ms: number): string {
    const seconds = Math.max(0, Math.ceil(ms / 1000));

    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

/** A moment as a sentence says it, "14:37", in the store's zone. */
export function useClock(): (iso: string) => string {
    const { locale } = usePage<SharedProps>().props;
    const zone = useStoreZone();

    return (iso) => new Intl.DateTimeFormat(intlLocale(locale), { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: zone }).format(new Date(iso));
}

/** Until when a payment can still go through: the hold limit (§1.7, gap 10.1). */
export function untilOf(view: CheckoutTryData): string | null {
    return view.holdEndsAt ?? view.waitEndsAt ?? view.windowEndsAt;
}

const REASONS = ['MF001', 'MF004', 'MF007', 'MF010'];

/** How a released try is told. "No money was taken" only on a decline or when nothing was charged (gaps 10.1, 10.2). */
export function endingOf(view: CheckoutTryData, t: ReturnType<typeof useTranslator>): string {
    if (view.refundDue || view.payment === 'NOT_ACCEPTED') {
        return t(`${P}.ended.not_accepted`);
    }

    if (view.declineCode !== null) {
        return REASONS.includes(view.declineCode) ? t(`${P}.ended.declined`, { reason: t(`${P}.ended.reason.${view.declineCode}`) }) : t(`${P}.ended.declined_general`);
    }

    if (view.payment === 'FAILED') {
        return view.nothingCharged ? t(`${P}.ended.could_not_start`) : t(`${P}.ended.declined_general`);
    }

    return view.nothingCharged ? t(`${P}.ended.nothing_taken`) : t(`${P}.ended.not_completed`);
}
