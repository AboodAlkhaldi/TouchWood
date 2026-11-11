import { lazy, Suspense, useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| Translate (content.md §1.3): beside every English box, for whoever may edit the item. It fills the box from its Arabic,
| and marks it "Translated, read it before publishing" until something is typed in it; it saves nothing. Translate All
| fills every English box that is empty. A save that shows the words to shoppers asks, while a box is still marked, for
| the one tick "I read the translated English." (`useReadTick`).
|
| The mark outlives the session (§1.3, the owner's P14): a form says on save whether a box still carries it
| (`translated`) and whether the tick was given (`read`); an item saved with it opens marked (`unread`), and only the
| tick clears it - typing over the words does not.
|
| The server does the work (`POST /admin/content/translate`, by the kind of item and its store); a refusal comes back in
| its own words and the box keeps what it held. The two questions load when asked (translate-dialogs.tsx): never on
| first paint, so React.lazy is safe under SSR (lesson 180).
*/

const ReplaceDialog = lazy(() => import('./translate-dialogs').then((module) => ({ default: module.ReplaceDialog })));
const ReadTickDialog = lazy(() => import('./translate-dialogs').then((module) => ({ default: module.ReadTickDialog })));

export type Pair = {
    /** The form's name of the pair, `text` for text_ar and text_en. */
    name: string;
    ar: string;
    en: string;
    /** Typed marks (§1.2): sent block by block, and how many lost their bold or links is said under the box. */
    rich?: boolean;
    fill: (english: string) => void;
};

type Answer = { texts: string[]; lostMarks: number[] };

export type Translation = {
    ready: boolean;
    /** The pairs carrying the mark; `*` the item's own mark from an earlier save, which only the tick clears. */
    marked: ReadonlySet<string>;
    busy: string | null;
    /** What is said under a pair's English box after Translate: a failure, or the marks it lost. */
    note: (name: string) => string | undefined;
    /** Typing in the English box takes its mark away. */
    typed: (name: string) => void;
    one: (pair: Pair) => void;
    all: (pairs: Pair[]) => void;
    /** The question before a typed English box is replaced. */
    dialog: ReactNode;
};

/** The mark an item saved earlier still carries: none of its boxes is named, and typing never takes it away. */
const SAVED_MARK = '*';

export function useTranslation(kind: string, store: string, ready: boolean, unread = false): Translation {
    const t = useTranslator();
    const { csrfToken } = usePage<SharedProps>().props;
    const [marked, setMarked] = useState<ReadonlySet<string>>(() => new Set(unread ? [SAVED_MARK] : []));
    const [notes, setNotes] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [replacing, setReplacing] = useState<Pair | null>(null);

    const send = async (pairs: Pair[], key: string) => {
        setBusy(key);
        setNotes((was) => Object.fromEntries(Object.entries(was).filter(([name]) => !pairs.some((pair) => pair.name === name))));

        try {
            const response = await fetch('/admin/content/translate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ kind, store, boxes: pairs.map((pair) => ({ text: pair.ar, rich: pair.rich === true })) }),
            });
            const body = (await response.json().catch(() => null)) as (Answer & { detail?: string }) | null;

            if (!response.ok || body === null || !Array.isArray(body.texts)) {
                const said = typeof body?.detail === 'string' ? body.detail : t('content::admin_shared.translate.failed');
                setNotes((was) => ({ ...was, ...Object.fromEntries(pairs.map((pair) => [pair.name, said])) }));

                return;
            }

            pairs.forEach((pair, index) => pair.fill(toLatinDigits(body.texts[index] ?? '')));
            setMarked((was) => new Set([...was, ...pairs.map((pair) => pair.name)]));
            setNotes((was) => {
                const next = { ...was };
                pairs.forEach((pair, index) => {
                    const lost = body.lostMarks[index] ?? 0;

                    if (lost > 0) {
                        next[pair.name] = t('content::admin_shared.translate.lost_marks', { count: lost });
                    }
                });

                return next;
            });
        } catch {
            setNotes((was) => ({ ...was, ...Object.fromEntries(pairs.map((pair) => [pair.name, t('content::admin_shared.translate.failed')])) }));
        } finally {
            setBusy(null);
        }
    };

    return {
        ready,
        marked,
        busy,
        note: (name) => notes[name],
        typed: (name) =>
            setMarked((was) => {
                if (!was.has(name)) {
                    return was;
                }

                const next = new Set(was);
                next.delete(name);

                return next;
            }),
        one: (pair) => (pair.en.trim() === '' ? void send([pair], pair.name) : setReplacing(pair)),
        all: (pairs) => {
            const empty = pairs.filter((pair) => pair.en.trim() === '' && pair.ar.trim() !== '');

            if (empty.length > 0) {
                void send(empty, 'all');
            }
        },
        dialog:
            replacing === null ? null : (
                <Suspense fallback={null}>
                    <ReplaceDialog
                        onKeep={() => setReplacing(null)}
                        onReplace={() => {
                            const pair = replacing;
                            setReplacing(null);
                            void send([pair], pair.name);
                        }}
                    />
                </Suspense>
            ),
    };
}

/** Translate beside one English box: out of reach, with the reason, while the Arabic is empty or no token is set. */
export function TranslateButton({ translation, pair }: { translation: Translation; pair: Pair }) {
    const t = useTranslator();
    const reason = !translation.ready ? t('content::admin_shared.translate.not_set') : pair.ar.trim() === '' ? t('content::admin_shared.translate.arabic_first') : undefined;

    return (
        <ActionButton type="button" variant="outline" size="sm" loading={translation.busy === pair.name} disabledReason={reason} onClick={() => translation.one(pair)} data-test={`translate-${pair.name}`}>
            <Languages aria-hidden />
            {t('content::admin_shared.translate.button')}
        </ActionButton>
    );
}

/** Translate All at the top of a form with several pairs: fills every English box that is empty. */
export function TranslateAllButton({ translation, pairs }: { translation: Translation; pairs: Pair[] }) {
    const t = useTranslator();
    const reason = !translation.ready
        ? t('content::admin_shared.translate.not_set')
        : !pairs.some((pair) => pair.en.trim() === '')
          ? t('content::admin_shared.translate.nothing_empty')
          : !pairs.some((pair) => pair.en.trim() === '' && pair.ar.trim() !== '')
            ? t('content::admin_shared.translate.arabic_first')
            : undefined;

    return (
        <div>
            <ActionButton type="button" variant="outline" loading={translation.busy === 'all'} disabledReason={reason} onClick={() => translation.all(pairs)} data-test="translate-all">
                <Languages aria-hidden />
                {t('content::admin_shared.translate.all')}
            </ActionButton>
        </div>
    );
}

/**
 * The one question before marked English reaches shoppers (§1.3 #3): a required tick, then the form's own button.
 * Nothing is asked while no box is marked: `ask` sends at once, saying no tick was given. The send is told whether it
 * was (`read`), for the server to clear the item's mark (P14).
 */
export function useReadTick(translation: Translation) {
    const [pending, setPending] = useState<{ send: () => void; label: string } | null>(null);

    return {
        ask: (send: (read: boolean) => void, label: string) => {
            if (translation.marked.size === 0) {
                send(false);

                return;
            }

            setPending({ send: () => send(true), label });
        },
        dialog:
            pending === null ? null : (
                <Suspense fallback={null}>
                    <ReadTickDialog
                        label={pending.label}
                        onClose={() => setPending(null)}
                        onSend={() => {
                            const go = pending.send;
                            setPending(null);
                            go();
                        }}
                    />
                </Suspense>
            ),
    };
}

/** What a save tells the server of Translate's mark (P14): whether a box still carries it, and whether the tick was given. */
export function markFields(translation: Translation, read: boolean): { translated: boolean; read: boolean } {
    return { translated: translation.marked.size > 0, read };
}
