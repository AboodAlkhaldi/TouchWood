import { useEffect, useRef, useState, type RefObject } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { PackingSizeData, PackingSizesData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { B, BOXES } from './parts';
import { SizeLine } from './SizeLine';

/*
| X3 · Add Sizes (shipping.md §4.1 part 3, B11): a search over the store's sizes not yet packed by hand, nothing
| archived (`ListPackingSizes`); several ticked, then Pack by Hand - one save per size, in one request (the
| backend session, 2026-10-31). Loaded when it is first opened.
*/

type Found = { state: 'idle' } | { state: 'loading' } | { state: 'failed' } | { state: 'ready'; page: PackingSizesData };

export function SizesDialog({ storeCode, onOpenChange, returnFocusTo }: { storeCode: string; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const { errors, locale } = usePage<SharedProps>().props;
    // The search the results belong to: a page that comes back for an older one is dropped.
    const asked = useRef('');
    const [moreFailed, setMoreFailed] = useState(false);
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<Found>({ state: 'idle' });
    const [ticked, setTicked] = useState<Map<string, PackingSizeData>>(() => new Map());
    const [busy, setBusy] = useState(false);
    const [attempt, setAttempt] = useState(0);

    const read = (text: string, after: string | null) =>
        fetch(`${BOXES}/sizes?${new URLSearchParams({ store: storeCode, hand: '0', q: text, ...(after !== null ? { after } : {}) }).toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        }).then((response) => (response.ok ? (response.json() as Promise<PackingSizesData>) : Promise.reject(new Error(String(response.status)))));

    // The search as it is typed, once it has two letters, a moment after the last one.
    useEffect(() => {
        const text = query.trim();

        if (text.length < 2) {
            setFound({ state: 'idle' });

            return;
        }

        let current = true;
        asked.current = text;
        const timer = window.setTimeout(() => {
            setFound({ state: 'loading' });
            read(text, null)
                .then((page) => current && setFound({ state: 'ready', page }))
                .catch(() => current && setFound({ state: 'failed' }));
        }, 300);

        return () => {
            current = false;
            window.clearTimeout(timer);
        };
    }, [query, attempt]);

    function more(page: PackingSizesData) {
        if (page.next === null) {
            return;
        }

        const text = query.trim();
        setMoreFailed(false);
        read(text, page.next)
            .then((next) => asked.current === text && setFound({ state: 'ready', page: { rows: [...page.rows, ...next.rows], next: next.next, total: next.total } }))
            // A failed page keeps the rows shown; Load More tries it again.
            .catch(() => asked.current === text && setMoreFailed(true));
    }

    function toggle(size: PackingSizeData, on: boolean) {
        setTicked((all) => {
            const next = new Map(all);

            if (on) {
                next.set(size.variantId, size);
            } else {
                next.delete(size.variantId);
            }

            return next;
        });
    }

    return (
        <PanelDialog
            wide
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${B}.search.title`)}
            description={t(`${B}.search.body`)}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    disabledReason={ticked.size === 0 ? t(`${B}.search.tick_one`) : undefined}
                    onClick={() =>
                        router.post(
                            `${BOXES}/sizes`,
                            { store: storeCode, variants: [...ticked.keys()], by_hand: true },
                            {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                                onSuccess: () => onOpenChange(false),
                                // Some refused: the saved ones are unticked, the refused stay ticked and marked, the search read again.
                                onError: (refused) => {
                                    const failed = new Set((refused.failed ?? '').split(',').filter(Boolean));
                                    setTicked((all) => new Map([...all].filter(([id]) => failed.has(id))));
                                    setAttempt((n) => n + 1);
                                },
                            },
                        )
                    }
                    data-test="confirm-pack-by-hand"
                >
                    {t(`${B}.search.confirm`)}
                    {ticked.size > 0 ? ` (${figure(locale, ticked.size)})` : ''}
                </ActionButton>
            }
        >
            <div className="grid gap-3">
                <label htmlFor="sizes-search" className="text-label-14 text-ink">
                    {t(`${B}.search.label`)}
                </label>
                <Input id="sizes-search" autoFocus value={query} onChange={(event) => setQuery(event.target.value)} placeholder={t(`${B}.search.placeholder`)} aria-describedby="sizes-search-hint" data-test="sizes-search" />
                <p id="sizes-search-hint" className="text-copy-13 text-ink-muted">
                    {t(`${B}.search.hint`)}
                </p>

                <p className="sr-only" role="status">
                    {found.state === 'loading' ? t(`${B}.search.loading`) : found.state === 'ready' ? t(`${B}.hand.count`, { shown: figure(locale, found.page.rows.length), total: figure(locale, found.page.total) }) : ''}
                </p>
                <div className="max-h-80 overflow-y-auto rounded-md border border-line" data-test="sizes-results">
                    {found.state === 'idle' ? null : found.state === 'loading' ? (
                        <p className="flex items-center gap-2 p-3 text-copy-14 text-ink-muted" role="status">
                            <Spinner aria-hidden="true" />
                            {t(`${B}.search.loading`)}
                        </p>
                    ) : found.state === 'failed' ? (
                        <div className="flex flex-wrap items-center gap-2 p-3">
                            <span className="text-copy-14 text-bad">{t(`${B}.search.failed`)}</span>
                            <Button type="button" variant="outline" size="sm" onClick={() => setAttempt((n) => n + 1)}>
                                {t(`${B}.search.try_again`)}
                            </Button>
                        </div>
                    ) : found.page.rows.length === 0 ? (
                        <p className="p-3 text-copy-14 text-ink-muted">{t(`${B}.search.none`, { query: query.trim() })}</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {found.page.rows.map((size) => (
                                <li key={size.variantId} className="flex items-center gap-3 px-3 py-2" data-test={`size-result-${size.variantId}`}>
                                    <Checkbox id={`tick-${size.variantId}`} checked={ticked.has(size.variantId)} onCheckedChange={(on) => toggle(size, on === true)} data-test={`tick-${size.variantId}`} />
                                    <SizeLine size={size} htmlFor={`tick-${size.variantId}`} />
                                    {(errors.failed ?? '').split(',').includes(size.variantId) ? <span className="text-copy-12 text-bad">{t(`${B}.search.refused`)}</span> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                {found.state === 'ready' && found.page.next !== null ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={() => more(found.page)} data-test="sizes-more">
                            {moreFailed ? t(`${B}.search.try_again`) : t(`${B}.search.more`)}
                        </Button>
                        {moreFailed ? <span className="text-copy-13 text-bad">{t(`${B}.search.failed`)}</span> : null}
                    </div>
                ) : null}
                {ticked.size > 0 ? <p className="text-copy-13 text-ink-muted">{t(`${B}.search.ticked`, { count: figure(locale, ticked.size) })}</p> : null}
            </div>
        </PanelDialog>
    );
}

