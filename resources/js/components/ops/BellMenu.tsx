import { useEffect, useId, useRef, useState, type RefObject } from 'react';
import { Link } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { BellRows } from '@/components/ops/BellRows';
import { Button } from '@/components/ui/button';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { readJson } from '@/lib/live-read';
import { ask, tell } from '@/lib/ops-bell';
import { recheck, useMarkAll } from '@/lib/ops-bell-marks';
import type { InboxEntryData, InboxPageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { BellWords } from '@/types/page';

/*
| A bell's menu (ops.md §4.15 O1, O2), on shadcn's Popover: the newest entries, read on each opening, and
| Mark All as Read while anything is unread. Loaded on the first press: it isn't on first paint, and on
| every page it would cost 10 KB and more (frontend.md §5).
*/

// The bell's one way to its later code: two would cost two lists of files on every page.
export { recheck };

type Props = {
    /** The bell's button, which the menu hangs from and gives focus back to. */
    anchor: RefObject<HTMLButtonElement | null>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    base: string;
    unread: number;
    words: BellWords;
    manyStores: boolean;
};

export function BellMenu({ anchor, open, onOpenChange, base, unread, words, manyStores }: Props) {
    const [entries, setEntries] = useState<InboxEntryData[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const heading = useRef<HTMLHeadingElement>(null);
    const headingId = useId();
    const { marking, failed: markFailed, markAll, clear } = useMarkAll(base, () => heading.current?.focus());

    // Each opening reads the newest entries, which also corrects the number.
    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const order = ask();
        setEntries(null);
        setFailed(false);
        clear();
        void readJson<InboxPageData>(base, {}, controller.signal).then((answer) => {
            if (controller.signal.aborted) {
                return;
            }

            setFailed(answer === null);

            if (answer !== null) {
                setEntries(answer.entries);
                tell(order, { unread: answer.unread });
            }
        });

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `clear` is new each render; only an opening reads.
    }, [open, attempt, base]);

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverAnchor virtualRef={anchor as RefObject<HTMLElement>} />
            <PopoverContent
                align="end"
                collisionPadding={16}
                className="grid w-[min(24rem,calc(100vw-2rem))] gap-2 p-2"
                aria-labelledby={headingId}
                data-test="bell-menu"
                // The button shuts the menu itself: as an outside press, it would shut it and open it again.
                onInteractOutside={(event) => {
                    if (anchor.current?.contains(event.detail.originalEvent.target as Node)) {
                        event.preventDefault();
                    }
                }}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    anchor.current?.focus();
                }}
            >
                <div className="flex flex-wrap items-center justify-between gap-2 px-2 py-1">
                    <h2 ref={heading} id={headingId} tabIndex={-1} className="text-label-14 font-medium outline-none">
                        {words.title}
                    </h2>
                    {/* Once the list is here: pressed while it is on its way, the list's late answer would undo it on screen. */}
                    {unread > 0 && entries !== null ? (
                        <ActionButton type="button" variant="ghost" size="sm" loading={marking} onClick={markAll} data-test="bell-mark-all">
                            {words.mark_all}
                        </ActionButton>
                    ) : null}
                </div>
                {markFailed ? (
                    <p role="alert" className="px-2 text-copy-13 text-bad" data-test="bell-mark-failed">
                        {words.mark_failed}
                    </p>
                ) : null}

                {failed ? (
                    <div className="grid justify-items-start gap-2 px-2 py-4" role="alert" data-test="bell-failed">
                        <p className="text-copy-14">{words.failed}</p>
                        <Button type="button" variant="outline" size="sm" onClick={() => setAttempt((count) => count + 1)} data-test="bell-retry">
                            {words.retry}
                        </Button>
                    </div>
                ) : entries === null ? (
                    <div className="grid gap-2 p-2" data-test="bell-loading">
                        <Skeleton className="h-14 w-full" />
                        <Skeleton className="h-14 w-full" />
                        <Skeleton className="h-14 w-full" />
                    </div>
                ) : entries.length === 0 ? (
                    <p className="px-2 py-4 text-copy-14 text-muted-foreground" data-test="bell-empty">
                        {words.empty}
                    </p>
                ) : (
                    <ScrollArea className="[&>[data-slot=scroll-area-viewport]]:max-h-[min(24rem,60vh)]">
                        <BellRows entries={entries} base={base} words={words} manyStores={manyStores} onGo={() => onOpenChange(false)} />
                    </ScrollArea>
                )}

                <Button asChild variant="outline" size="sm" className="w-full">
                    <Link href={base} onClick={() => onOpenChange(false)} data-test="bell-view-all">
                        {words.view_all}
                    </Link>
                </Button>
            </PopoverContent>
        </Popover>
    );
}
