import { useEffect, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { DirectionProvider } from '@/components/ui/direction';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { Direction } from '@/types/page';

/*
| What shadcn's components need from above every page (frontend.md §1.11), wrapped around Inertia's
| App in both the browser and the server entry.
|
| - **DirectionProvider** (edit 3): Radix's menus, tabs and sliders read the direction from here, not
|   from <html dir>, so without it their arrow keys and their placement stay left-to-right in Arabic.
| - **TooltipProvider**: shadcn's install asks for one at the root, so every tooltip shares one delay.
|
| The direction is the page's own, and it changes without a reload: choosing Arabic is an Inertia
| visit, and the root is never mounted again. So it starts from the first page and follows every
| page after it, the way SyncDocument keeps <html> in step.
*/

export function Providers({ direction, children }: { direction: Direction; children: ReactNode }) {
    const [dir, setDir] = useState<Direction>(direction);

    useEffect(
        () =>
            router.on('navigate', (event) => {
                const next = (event.detail.page.props as { direction?: unknown }).direction;

                if (next === 'rtl' || next === 'ltr') {
                    setDir(next);
                }
            }),
        [],
    );

    return (
        <DirectionProvider dir={dir}>
            <TooltipProvider>{children}</TooltipProvider>
        </DirectionProvider>
    );
}

/** The direction the first page arrived in, read from its shared props (frontend.md §1.5). */
export function firstDirection(props: unknown): Direction {
    const direction = (props as { direction?: unknown } | undefined)?.direction;

    return direction === 'rtl' ? 'rtl' : 'ltr';
}
