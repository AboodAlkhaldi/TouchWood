import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| What the last request had to say, at the bottom centre (frontend.md §2.1).
|
| A success is the flash `status`: "Invitation sent.", "Settings saved." A business error shows
| twice - here as a red toast, and as a red message beside what it concerns, which the form itself
| renders. The toast fades; the message beside the field stays until the person changes the form.
|
| Rendered on the server too, so the first paint already holds the message. The fading is an effect,
| which only runs in the browser - there is no timer on the server, and nothing here reads `window`.
*/

const FADE_AFTER_MS = 6000;

type Toast = {
    id: string;
    tone: 'good' | 'bad';
    message: string;
};

export function Toasts() {
    const { flash, errors, store } = usePage<SharedProps>().props;
    const t = useTranslator();

    // Counted, not remembered by content. Marking a message dismissed by its own text means the
    // same message can never appear again: a person who gets the password wrong twice sees the
    // refusal once and then nothing at all, which reads as the button being broken (found by
    // running it, 2026-09-22). Every finished visit is a new chance to say the same thing.
    const [visit, setVisit] = useState(0);
    const [hiddenFor, setHiddenFor] = useState(-1);

    useEffect(() => router.on('finish', () => setVisit((count) => count + 1)), []);

    // "You no longer have access to that store - showing KSA." The server decided it; this is
    // where the person is told (frontend.md 2.2).
    const fellBack =
        store?.fellBack === true && store.current !== null
            ? t('admin.store.fell_back', { store: store.current.name })
            : null;

    const toasts: Toast[] = [
        ...(flash.status ? [{ id: `good:${flash.status}`, tone: 'good' as const, message: flash.status }] : []),
        ...(fellBack ? [{ id: `bad:fell_back`, tone: 'bad' as const, message: fellBack }] : []),
        // `form` is the business error of §1.7: the one that belongs to no single field.
        ...(errors.form ? [{ id: `bad:${errors.form}`, tone: 'bad' as const, message: errors.form }] : []),
    ];

    const showing = hiddenFor === visit ? [] : toasts;

    useEffect(() => {
        if (showing.length === 0) {
            return;
        }

        const timer = window.setTimeout(() => setHiddenFor(visit), FADE_AFTER_MS);

        return () => window.clearTimeout(timer);
        // Keyed by the visit: a new answer from the server restarts the clock, whether or not it
        // says the same thing as the last one.
    }, [visit, showing.length]);

    if (showing.length === 0) {
        return null;
    }

    return (
        <div
            className="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex flex-col items-center gap-2 px-4"
            aria-live="polite"
        >
            {showing.map((toast) => (
                <div
                    key={toast.id}
                    className={[
                        'pointer-events-auto max-w-md rounded-lg px-4 py-3 text-sm shadow-pop',
                        toast.tone === 'good'
                            ? 'bg-good-soft text-good border border-good/30'
                            : 'bg-bad-soft text-bad border border-bad/30',
                    ].join(' ')}
                >
                    {toast.message}
                </div>
            ))}
        </div>
    );
}
