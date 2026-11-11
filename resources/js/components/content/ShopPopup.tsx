import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ContentLink, shopHref } from '@/components/content/ContentLink';
import { ShopPhoto } from '@/components/shop/ShopPhoto';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { ContentFrame, SharedProps } from '@/types/page';

/*
| The pop-up (content.md §1.10), loaded by the layout only once a page carries one, and drawn only in the browser, after
| its delay - never by the server, so it costs the first paint nothing (plan K11).
|
| - **Once per visitor per pop-up**, kept in this browser under one key holding the ids shown; **storage refused, it
|   is not shown at all**, so nobody is nagged `[ACCEPTED 2026-11-10]`.
| - **Small on a phone**: a Sheet from the bottom, at most 40% of the height, its image left out, text that runs over
|   scrolling inside it. **Wider: a Dialog of 420 px** with its image. It closes on the X, Escape, a press outside and
|   its button, and the focus goes back where it was (Radix).
*/

type Popup = NonNullable<ContentFrame['popup']>;

/** The pop-ups this browser has shown: one key, their ids, the last 50. */
const SEEN = 'tw:popups';

export default function ShopPopup({ popup }: { popup: Popup }) {
    const { shop, locale } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);

    useEffect(() => {
        let seen: unknown;

        try {
            seen = JSON.parse(window.localStorage.getItem(SEEN) ?? '[]');
        } catch {
            return;
        }

        const ids = Array.isArray(seen) ? seen.filter((id): id is string => typeof id === 'string') : [];

        if (ids.includes(popup.id)) {
            return;
        }

        const timer = window.setTimeout(() => {
            try {
                window.localStorage.setItem(SEEN, JSON.stringify([...ids.slice(-49), popup.id]));
            } catch {
                return;
            }

            setOpen(true);
        }, popup.delaySeconds * 1000);

        return () => window.clearTimeout(timer);
    }, [popup.id, popup.delaySeconds]);

    if (!open || shop === null || shop === undefined) {
        return null;
    }

    const button =
        popup.button !== null && shopHref(popup.link, shop.code, locale) !== null ? (
            <Button asChild onClick={() => setOpen(false)}>
                <ContentLink link={popup.link} store={shop.code} locale={locale} test="popup-button">
                    {popup.button}
                </ContentLink>
            </Button>
        ) : null;

    if (window.matchMedia('(max-width: 640px)').matches) {
        return (
            <Sheet open onOpenChange={setOpen}>
                <SheetContent side="bottom" className="max-h-[40vh] gap-3 overflow-y-auto rounded-t-xl" data-test="popup" data-kind="sheet">
                    <SheetHeader className="pe-10">
                        <SheetTitle>{popup.title}</SheetTitle>
                        <SheetDescription className="text-copy-14 whitespace-pre-line text-ink">{popup.text}</SheetDescription>
                    </SheetHeader>
                    {button === null ? null : <SheetFooter className="pt-0">{button}</SheetFooter>}
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open onOpenChange={setOpen}>
            <DialogContent className="max-w-[420px] sm:max-w-[420px]" data-test="popup" data-kind="dialog">
                {Object.keys(popup.image).length === 0 ? null : <ShopPhoto photo={popup.image} size="card" alt="" className="aspect-video w-full rounded-md" />}
                <DialogHeader>
                    <DialogTitle>{popup.title}</DialogTitle>
                    <DialogDescription className="text-copy-14 whitespace-pre-line text-ink">{popup.text}</DialogDescription>
                </DialogHeader>
                {button === null ? null : <DialogFooter>{button}</DialogFooter>}
            </DialogContent>
        </Dialog>
    );
}
