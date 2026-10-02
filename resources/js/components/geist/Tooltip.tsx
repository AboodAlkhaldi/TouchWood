import type { ReactNode } from 'react';
import { Tooltip as TooltipPrimitive } from 'radix-ui';

/*
| Geist's Tooltip (frontend.md 1.10): explains why, not what - the constraint, the scope, the limit.
|
| Opens on hover and on keyboard focus after Geist's ~150 ms, so a sweeping mouse does not flash it;
| Escape closes it. It is the only floating surface with a stem. Never wrapped around a labelled
| input (put help on a sibling icon button), and never holding an action a touch screen can't reach.
|
| Each tooltip brings its own provider, so a screen never has to remember to mount one.
*/

type Props = {
    text: ReactNode;
    children: ReactNode;
    side?: 'top' | 'bottom' | 'left' | 'right';
};

export function Tooltip({ text, children, side = 'top' }: Props) {
    return (
        <TooltipPrimitive.Provider delayDuration={150}>
            <TooltipPrimitive.Root>
                <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
                <TooltipPrimitive.Portal>
                    <TooltipPrimitive.Content
                        side={side}
                        sideOffset={6}
                        collisionPadding={8}
                        className="material-tooltip z-50 max-w-xs px-2.5 py-1.5 text-copy-13 text-ink"
                    >
                        {text}
                        <TooltipPrimitive.Arrow className="fill-surface" width={10} height={5} />
                    </TooltipPrimitive.Content>
                </TooltipPrimitive.Portal>
            </TooltipPrimitive.Root>
        </TooltipPrimitive.Provider>
    );
}
