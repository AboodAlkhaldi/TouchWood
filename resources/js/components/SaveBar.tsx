import { useEffect, useRef, useState } from 'react';
import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { type HeldVisit, type LeaveGuard, leaveGuard } from '@/lib/leave-guard';
import { useTranslator } from '@/lib/t';

/*
| Rows that hold boxes (pricing.md §4.3.1; Q40): while any box is changed, a bar at the bottom of the
| content says how many - "3 prices changed" - with **Cancel** and the page's own save, "Save Prices",
| "Save Counts". Neither Geist nor shadcn has the bar; it is built from their parts - a `material-menu`
| surface, shadcn's Button, ActionButton's `loading` - as the owner chose. It is not the page's main
| action, which stays where it is.
|
| It sits at the end of the page's content and sticks to the bottom of the window while the rows scroll
| under it, so it is as wide as the content, never across the sidebar. Its count is a polite live region:
| a screen reader hears "3 prices changed" as the count changes, not at every keystroke of one box.
|
| `useLeaveGuard` asks before a visit would lose the boxes (§4.3.1, "Leaving with changed boxes"). The
| bar's count is also said from a polite live region that is always there - empty while nothing is
| changed - so the first "1 price changed" is announced, not only the ones after it.
*/

type SaveBarProps = {
    /** How many boxes are changed; the bar is drawn while it is more than none. */
    count: number;
    /** "3 prices changed", written by the page in its own words and plural. */
    message: string;
    /** The page's own save: "Save Prices". */
    saveLabel: string;
    saving: boolean;
    onSave: () => void;
    onCancel: () => void;
};

export function SaveBar({ count, message, saveLabel, saving, onSave, onCancel }: SaveBarProps) {
    const t = useTranslator();
    const shown = count > 0;

    return (
        <>
            {/* Always there, empty while nothing is changed, so its first message is announced too. */}
            <p className="sr-only" aria-live="polite" data-test="save-bar-live">
                {shown ? message : ''}
            </p>
            {shown ? (
                <div
                    role="region"
                    aria-labelledby="save-bar-message"
                    className="material-menu sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    data-test="save-bar"
                >
                    <p id="save-bar-message" className="tw-figure text-label-14 text-ink">
                        {message}
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" disabled={saving} onClick={onCancel} data-test="save-bar-cancel">
                            {t('ui.cancel')}
                        </Button>
                        <ActionButton type="button" loading={saving} onClick={onSave} data-test="save-bar-save">
                            {saveLabel}
                        </ActionButton>
                    </div>
                </div>
            ) : null}
        </>
    );
}

/**
 * A box typed with another value than the one saved (§4.3.1): a dot and "Changed", in words as well as
 * colour, said with the box (give its id to the box's aria-describedby).
 */
export function ChangedMark({ id }: { id?: string }) {
    const t = useTranslator();

    return (
        <span id={id} className="inline-flex items-center gap-1 text-copy-12 text-ink-muted" data-test="changed">
            <span aria-hidden="true" className="size-1.5 rounded-full bg-info" />
            {t('ui.changed')}
        </span>
    );
}

type LeaveWords = {
    /** What would be lost: "3 changed prices will be lost." */
    description: string;
};

/**
 * Asks before leaving with changed boxes (pricing.md §4.3.1): a link, a filter, the search or the store
 * filter - any Inertia visit that would replace the page - is held, and `PanelDialog` asks "Leave
 * Without Saving" with **Leave Page** and **Stay**; leaving makes the same visit again, its options and
 * callbacks kept. A prefetch is never held: it replaces nothing. Closing or
 * reloading the window asks through the browser's own question, the only one it allows.
 *
 * *Not covered:* the browser's Back button. Inertia restores the page before any event a page could
 * cancel (its `popstate` handler fires no `before`), so going back loses the boxes without asking.
 *
 * @param keeps  a visit that keeps the boxes, beside `keepsBoxes` - the page's own judgement
 */
export function useLeaveGuard(dirty: boolean, words: LeaveWords, keeps: (visit: PendingVisit) => boolean = () => false) {
    const [held, setHeld] = useState<HeldVisit | null>(null);
    const latest = useRef({ dirty, keeps });
    latest.current = { dirty, keeps };
    // Its decisions (lib/leave-guard.ts, tested there): made once, reading the latest state.
    const guard = useRef<LeaveGuard | null>(null);
    guard.current ??= leaveGuard({
        dirty: () => latest.current.dirty,
        keeps: (visit) => latest.current.keeps(visit),
        hold: setHeld,
        visit: (url, options) => router.visit(url, options),
    });

    useEffect(() => {
        const stop = router.on('before', (event) => (guard.current?.before(event.detail.visit as HeldVisit) === false ? false : undefined));
        const unload = (event: BeforeUnloadEvent) => {
            if (latest.current.dirty && guard.current?.leaving() !== true) {
                event.preventDefault();
                // Older browsers ask only when a value is set; its words are the browser's own.
                event.returnValue = '';
            }
        };

        window.addEventListener('beforeunload', unload);

        return () => {
            stop();
            window.removeEventListener('beforeunload', unload);
        };
    }, []);

    return (
        <LeaveDialog
            visit={held}
            description={words.description}
            onStay={() => setHeld(null)}
            onLeave={(visit) => {
                setHeld(null);
                guard.current?.leave(visit);
            }}
        />
    );
}

function LeaveDialog({ visit, description, onStay, onLeave }: { visit: HeldVisit | null; description: string; onStay: () => void; onLeave: (visit: HeldVisit) => void }) {
    const t = useTranslator();

    return (
        <PanelDialog
            open={visit !== null}
            onOpenChange={(open) => (open ? undefined : onStay())}
            destructive
            title={t('ui.leave.title')}
            description={description}
            busy={false}
            cancelLabel={t('ui.leave.stay')}
            confirm={
                <ActionButton type="button" variant="destructive" onClick={() => (visit === null ? undefined : onLeave(visit))} data-test="leave-page">
                    {t('ui.leave.confirm')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
