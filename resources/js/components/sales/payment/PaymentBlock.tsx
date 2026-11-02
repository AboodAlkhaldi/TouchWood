import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CheckoutTryData, PayStartData, PaymentFormData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { CHANNEL, P, endingOf, minutesAndSeconds, postJson, untilOf, useClock, useCsrfToken, useServerNow } from './flow';

/*
| The Payment card's part while paying online (payments.md §1.4, §4.2). MyFatoorah's form in Collect Details mode: it only
| collects, our server charges, so nothing moves before our checks. Pay (the summary's button) checks the form and asks it
| to collect; Apple Pay and STC Pay hand over from their own buttons. Either way our server gets the quote and the session,
| never an amount. From then on the page is locked, shows the time left and the bank's page in a frame, and asks our
| server what happened: a message from the frame is only a reason to ask (rule 1).
*/

declare global {
    interface Window {
        myfatoorah?: {
            init: (config: Record<string, unknown>) => void;
            validateCardInputs: () => Promise<unknown>;
            submitCardPayment: () => void;
        };
    }
}

const FORM_BOX = 'payment-form';
/** A press the form never answers gives the page back rather than leave it locked. */
const PRESS_LIMIT = 60000;
const ASK_WHILE_ON_BANK_PAGE = 30000;
const ASK_WHILE_CONFIRMING = 10000;

type Live = { tryId: string; frameUrl: string | null; windowEndsAt: string; serverNow: string; frameOpen: boolean; finished: boolean; view: CheckoutTryData | null };
type Phase = { kind: 'opening' } | { kind: 'failed'; words: string } | { kind: 'form' } | { kind: 'pressed' } | { kind: 'live'; live: Live } | { kind: 'reloading' };

export type OnlineState = { ready: boolean; locked: boolean };

const scripts = new Map<string, Promise<void>>();

/** The form's script, from the address our server named for the mode (rule 10), loaded once and only on a page that pays. */
function loadScript(src: string): Promise<void> {
    let loading = scripts.get(src);

    if (loading === undefined) {
        loading = new Promise<void>((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => {
                scripts.delete(src);
                script.remove();
                reject(new Error('The form did not load.'));
            };
            document.head.appendChild(script);
        });
        scripts.set(src, loading);
    }

    return loading;
}

export default function PaymentBlock({ quoteId, press, onState, onClosed }: { quoteId: string; press: RefObject<(() => void) | null>; onState: (state: OnlineState) => void; onClosed: (view: CheckoutTryData) => void }) {
    const t = useTranslator();
    const link = useLink();
    const token = useCsrfToken();
    const [phase, setPhase] = useState<Phase>({ kind: 'opening' });
    const [notice, setNotice] = useState<string | null>(null);
    const session = useRef<string | null>(null);
    const quote = useRef(quoteId);
    quote.current = quoteId;

    // Over: the page again from the server, a new quote and so a new session (§1.4 step 6).
    const again = useCallback((words: string | null) => {
        setNotice(words);
        setPhase({ kind: 'reloading' });
        router.reload({ onFinish: () => setPhase({ kind: 'opening' }) });
    }, []);

    /** The details are ready: our server starts the try, with the quote and the session alone. */
    const send = useCallback(async () => {
        setNotice(null);
        setPhase({ kind: 'pressed' });
        const answer = await postJson<PayStartData>(link('storefront.sales.payment.pay'), token, { quote: quote.current, session: session.current ?? '' });

        if (answer.ok) {
            const { tryId, frameUrl, windowEndsAt, serverNow } = answer.data;
            setPhase({ kind: 'live', live: { tryId, frameUrl, windowEndsAt, serverNow, frameOpen: frameUrl !== null, finished: frameUrl === null, view: null } });
        } else if (answer.status === 429) {
            setNotice(t(`${P}.too_many`));
            setPhase({ kind: 'form' });
        } else if (answer.status === 0) {
            // Nobody knows whether it started: the server says, when the page opens again.
            again(null);
        } else {
            again(answer.type.includes('quote_changed') ? t(`${P}.changed`) : answer.detail);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [again, token]);

    // The form for this quote.
    const quoteOpened = useRef(quoteId);

    useEffect(() => {
        if (quoteOpened.current !== quoteId && (phase.kind === 'form' || phase.kind === 'failed')) {
            quoteOpened.current = quoteId;
            setPhase({ kind: 'opening' });
        }
    }, [quoteId, phase.kind]);

    useEffect(() => {
        if (phase.kind !== 'opening') {
            return undefined;
        }

        let gone = false;

        void (async () => {
            quoteOpened.current = quoteId;
            const answer = await postJson<PaymentFormData>(link('storefront.sales.payment.session'), token, { quote: quoteId });

            if (gone) {
                return;
            }

            if (!answer.ok) {
                if (answer.status === 409) {
                    // A try started in another window since this page was drawn.
                    again(null);
                } else {
                    setPhase({ kind: 'failed', words: answer.status === 429 ? t(`${P}.too_many`) : t(`${P}.form_failed`) });
                }

                return;
            }

            try {
                await loadScript(answer.data.scriptUrl);
            } catch {
                if (!gone) {
                    setPhase({ kind: 'failed', words: t(`${P}.form_failed`) });
                }

                return;
            }

            if (gone || window.myfatoorah === undefined) {
                return;
            }

            session.current = answer.data.sessionId;
            document.getElementById(FORM_BOX)?.replaceChildren();
            window.myfatoorah.init({
                sessionId: answer.data.sessionId,
                containerId: FORM_BOX,
                callback: (response: { isSuccess?: boolean }) => {
                    if (response.isSuccess === true) {
                        void send();
                    } else {
                        setNotice(t(`${P}.form_failed`));
                        setPhase({ kind: 'form' });
                    }
                },
                settings: { loader: { display: 'none' }, card: { style: { button: { useCustomButton: true } } } },
            });
            setPhase({ kind: 'form' });
        })();

        return () => {
            gone = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [phase.kind, quoteId]);

    // Our Pay: the form checks itself, then collects; its callback sends.
    press.current = () => {
        if (phase.kind !== 'form' || window.myfatoorah === undefined) {
            return;
        }

        setNotice(null);
        setPhase({ kind: 'pressed' });
        window.myfatoorah
            .validateCardInputs()
            .then(() => window.myfatoorah?.submitCardPayment())
            .catch(() => setPhase({ kind: 'form' }));
    };

    useEffect(() => {
        if (phase.kind !== 'pressed') {
            return undefined;
        }

        const timer = setTimeout(() => setPhase((now) => (now.kind === 'pressed' ? { kind: 'form' } : now)), PRESS_LIMIT);

        return () => clearTimeout(timer);
    }, [phase.kind]);

    useEffect(() => {
        onState({ ready: phase.kind === 'form', locked: phase.kind === 'pressed' || phase.kind === 'live' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [phase.kind]);

    return (
        <div className="grid gap-3" data-test="payment-block">
            {notice === null ? null : (
                <p role="status" className="text-copy-14 text-ink" data-test="payment-notice">
                    {notice}
                </p>
            )}
            {phase.kind === 'failed' ? (
                <div className="grid justify-items-start gap-2" data-test="payment-failed">
                    <p className="text-copy-14 text-ink">{phase.words}</p>
                    <Button variant="outline" onClick={() => setPhase({ kind: 'opening' })} data-test="payment-try-again">
                        {t(`${P}.try_again`)}
                    </Button>
                </div>
            ) : null}
            {phase.kind === 'opening' || phase.kind === 'reloading' ? <p className="text-copy-14 text-ink-muted">{t(`${P}.loading`)}</p> : null}
            {phase.kind === 'live' ? (
                <Paying
                    live={phase.live}
                    change={(next) => setPhase((now) => (now.kind === 'live' ? { kind: 'live', live: { ...now.live, ...next } } : now))}
                    onOver={(view) => {
                        if (view.mainStoreUrl !== null && view.firstSeen) {
                            onClosed(view);
                        } else if (view.mainStoreUrl !== null) {
                            window.location.reload();
                        } else if (view.orderNumber !== null) {
                            router.visit(link('storefront.sales.placed', { number: view.orderNumber }));
                        } else {
                            again(endingOf(view, t));
                        }
                    }}
                    onLost={() => again(null)}
                />
            ) : null}
            {/* Kept while the page is locked: the form stays in its place, out of reach. Only MyFatoorah draws in it. */}
            <div id={FORM_BOX} inert={phase.kind === 'pressed' || phase.kind === 'live'} hidden={phase.kind === 'failed'} className="min-w-0" data-test="payment-form" />
        </div>
    );
}

/** From Pay to the answer: the time left, the bank's page, and asking our server. */
function Paying({ live, change, onOver, onLost }: { live: Live; change: (next: Partial<Live>) => void; onOver: (view: CheckoutTryData) => void; onLost: () => void }) {
    const t = useTranslator();
    const link = useLink();
    const token = useCsrfToken();
    const clock = useClock();
    const now = useServerNow(live.serverNow);
    const frame = useRef<HTMLIFrameElement>(null);
    const [leaving, setLeaving] = useState(false);
    const left = new Date(live.windowEndsAt).getTime() - now;
    const windowOver = left <= 0;
    const latest = useRef(live);
    latest.current = live;
    const asking = useRef(false);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const gone = useRef(false);

    const ask = useCallback(async () => {
        if (asking.current || gone.current) {
            return;
        }

        asking.current = true;
        clearTimeout(timer.current);
        const answer = await postJson<CheckoutTryData>(link('storefront.sales.payment.follow', { try: latest.current.tryId }), token);
        asking.current = false;

        if (gone.current) {
            return;
        }

        if (answer.ok && answer.data.state !== 'LIVE') {
            onOver(answer.data);

            return;
        }

        if (!answer.ok && (answer.status === 404 || answer.status === 401 || answer.status === 419)) {
            onLost();

            return;
        }

        if (answer.ok) {
            change({ view: answer.data });
        }

        const untilWindow = new Date(latest.current.windowEndsAt).getTime() - Date.now();
        const every = latest.current.finished || untilWindow <= 0 ? ASK_WHILE_CONFIRMING : ASK_WHILE_ON_BANK_PAGE;
        timer.current = setTimeout(() => void ask(), every);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [token]);

    useEffect(() => {
        gone.current = false;
        void ask();

        return () => {
            gone.current = true;
            clearTimeout(timer.current);
        };
    }, [ask]);

    // The window over: our page ends it, and asks at once (§1.7).
    useEffect(() => {
        if (windowOver) {
            change({ frameOpen: false });
            setLeaving(false);
            void ask();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [windowOver]);

    // Believed only from the frame we opened, at the address it opened, and even then it only makes us ask (rule 7).
    useEffect(() => {
        if (live.frameUrl === null) {
            return undefined;
        }

        const bank = new URL(live.frameUrl).origin;
        const heard = (event: MessageEvent) => {
            if (frame.current === null || event.source !== frame.current.contentWindow) {
                return;
            }

            const ours = event.origin === window.location.origin && (event.data as { sender?: string } | null)?.sender === 'TW-payment-return';
            let theirs = false;

            if (event.origin === bank) {
                try {
                    theirs = (JSON.parse(String(event.data)) as { sender?: string }).sender === 'MF-3DSecure';
                } catch {
                    theirs = false;
                }
            }

            if (ours || theirs) {
                change({ frameOpen: false, finished: true });
                setLeaving(false);
                void ask();
            }
        };

        window.addEventListener('message', heard);

        return () => window.removeEventListener('message', heard);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [live.frameUrl, ask]);

    // A second window asks whether anyone is paying (§1.4 step 9).
    useEffect(() => {
        if (typeof BroadcastChannel === 'undefined') {
            return undefined;
        }

        const channel = new BroadcastChannel(CHANNEL);
        channel.onmessage = (event) => {
            if (event.data === 'ping') {
                channel.postMessage('here');
            }
        };

        return () => channel.close();
    }, []);

    const view = live.view;
    const until = (view === null ? null : untilOf(view)) ?? live.windowEndsAt;
    const waitOver = view?.waitEndsAt !== null && view?.waitEndsAt !== undefined && new Date(view.waitEndsAt).getTime() <= now;

    return (
        <>
            {view?.payment === 'UNKNOWN' && waitOver ? (
                <Note data-test="payment-still-confirming">{t(`${P}.still_confirming`)}</Note>
            ) : live.finished ? (
                <p role="status" className="text-label-14 text-ink" data-test="payment-confirming">
                    {t(`${P}.confirming`)}
                </p>
            ) : windowOver ? (
                <p role="status" className="text-label-14 text-ink" data-test="payment-ran-out">
                    {t(`${P}.ran_out`)}
                </p>
            ) : (
                <p className="flex items-center gap-2 text-copy-14 text-ink" data-test="payment-time">
                    {t(`${P}.time_left`)}
                    <span className="tw-figure text-label-14" dir="ltr" data-test="payment-time-left">
                        {minutesAndSeconds(left)}
                    </span>
                </p>
            )}
            {live.frameUrl === null ? null : (
                <Dialog open={live.frameOpen} onOpenChange={(open) => (open ? undefined : setLeaving(true))}>
                    <DialogContent className="top-0 left-0 flex h-dvh max-w-none translate-x-0 translate-y-0 flex-col rounded-none p-4 sm:top-[50%] sm:left-[50%] sm:h-[85vh] sm:max-w-2xl sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-lg" data-test="payment-frame-dialog">
                        <DialogHeader>
                            <DialogTitle>{t(`${P}.frame.title`)}</DialogTitle>
                            <DialogDescription>{t(`${P}.frame.body`)}</DialogDescription>
                        </DialogHeader>
                        {leaving ? (
                            <div role="alert" className="grid gap-2 rounded-md bg-surface-sunken p-3" data-test="payment-leave">
                                <p className="text-label-14 text-ink">{t(`${P}.frame.leave_title`)}</p>
                                <p className="text-copy-14 text-ink">{t(`${P}.frame.leave_body`, { time: clock(until) })}</p>
                                <div className="flex flex-wrap gap-2">
                                    <ActionButton variant="outline" onClick={() => setLeaving(false)} data-test="payment-stay">
                                        {t(`${P}.frame.stay`)}
                                    </ActionButton>
                                    <ActionButton
                                        variant="outline"
                                        onClick={() => {
                                            setLeaving(false);
                                            change({ frameOpen: false });
                                        }}
                                        data-test="payment-leave-confirm"
                                    >
                                        {t(`${P}.frame.leave`)}
                                    </ActionButton>
                                </div>
                            </div>
                        ) : null}
                        {/* Only the address our server received from MyFatoorah (rule 10). Stays mounted while asked about leaving. */}
                        <iframe ref={frame} src={live.frameUrl} title={t(`${P}.frame.title`)} className="min-h-0 w-full flex-1 rounded-md border border-line bg-white" data-test="payment-frame" />
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}
