import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Bell as BellIcon } from 'lucide-react';
import type { BellMenu } from '@/components/ops/BellMenu';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { figure } from '@/lib/digits';
import { useBellNumber } from '@/lib/ops-bell';
import type { BellWords, SharedProps } from '@/types/page';

/*
| A bell's button and number, in the panel's top bar and the shop's header (ops.md §4.15 O1, O2). Neither
| Geist nor shadcn has a notification centre (checked 2026-11-02), so the bell is put together from shadcn's
| Button, Badge, Popover, Item and Scroll Area; everything but the button and number loads on the first
| press (BellMenu).
|
| **No timers (Q7):** the number comes with each page, the list is read on pressing the bell, and only
| staff read the number once more, on coming back to the tab after at least a minute (`recheck`). One read
| is 8 queries with a write, and any request resets a staff session's idle clock.
|
| The addresses are written out from `base`: the route helper would add its weight to every page
| (frontend.md §5, as ViewStoreButton does).
*/

/** A minute away before the number is worth asking again (Q7). */
const AWAY_MS = 60_000;

/** One place that loads the rest: each `import()` written out costs its own preload list, on every page. */
const loadMenu = () => import('@/components/ops/BellMenu');

type Props = {
    /** The bell's address: the list's, and the marks' under it. */
    base: string;
    unread: number;
    words: BellWords;
    manyStores?: boolean;
    /** Staff only (Q7): the number asked once on coming back to the tab after at least a minute. */
    recheck?: boolean;
};

export function Bell({ base, unread: fromServer, words, manyStores = false, recheck = false }: Props) {
    const { locale } = usePage<SharedProps>().props;
    const unread = useBellNumber(fromServer);
    const [open, setOpen] = useState(false);
    const [Menu, setMenu] = useState<typeof BellMenu | null>(null);
    const button = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!recheck) {
            return;
        }

        // A tab may open behind another, or a page arrive while it is hidden: that counts as away too.
        let hiddenAt: number | null = document.hidden ? Date.now() : null;
        const changed = () => {
            if (document.hidden) {
                hiddenAt = Date.now();

                return;
            }

            if (hiddenAt !== null && Date.now() - hiddenAt >= AWAY_MS) {
                void loadMenu().then((loaded) => loaded.recheck(base));
            }

            hiddenAt = null;
        };
        document.addEventListener('visibilitychange', changed);

        return () => document.removeEventListener('visibilitychange', changed);
    }, [base, recheck]);

    function press() {
        if (Menu !== null) {
            setOpen(!open);
        } else {
            // A failed load leaves the bell as it was: the next press asks again.
            void loadMenu().then(
                (loaded) => {
                    setMenu(() => loaded.BellMenu);
                    setOpen(true);
                },
                () => undefined,
            );
        }
    }

    const name = unread > 0 ? words.name_unread.replace(':count', figure(locale, unread)) : words.title;

    return (
        <>
            <Button ref={button} type="button" variant="ghost" size="icon" className="relative" aria-label={name} aria-haspopup="dialog" aria-expanded={open} onClick={press} data-test="bell">
                <BellIcon aria-hidden="true" />
                {unread > 0 ? (
                    <Badge className="tw-figure absolute -end-1 -top-1 h-5 min-w-5 rounded-full px-1 text-[11px] leading-none" aria-hidden="true" data-test="bell-number">
                        {unread > 99 ? '99+' : figure(locale, unread)}
                    </Badge>
                ) : null}
            </Button>
            {Menu === null ? null : <Menu anchor={button} open={open} onOpenChange={setOpen} base={base} unread={unread} words={words} manyStores={manyStores} />}
        </>
    );
}
