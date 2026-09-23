import type { ComponentProps } from 'react';
import { Switch as SwitchPrimitive } from 'radix-ui';

/*
| An on/off switch that saves the moment it is flipped (frontend.md §3.2, B4).
|
| The thumb is moved by flex alignment, not by a translate: `justify-end` already means the far end
| in whichever direction the page runs, so an Arabic page needs no mirrored rule and cannot end up
| with a switch that slides the wrong way.
|
| 24 by 44 pixels, which clears the 24-by-24 touch target §6 asks for. Radix gives it the switch
| role, the checked state and keyboard operation; the label beside it is the caller's.
*/

type Props = ComponentProps<typeof SwitchPrimitive.Root>;

export function Switch({ className = '', ...props }: Props) {
    return (
        <SwitchPrimitive.Root
            {...props}
            className={`inline-flex h-6 w-11 shrink-0 items-center justify-start rounded-pill border border-line-strong bg-surface-sunken p-0.5 transition-colors data-[state=checked]:justify-end data-[state=checked]:border-brand data-[state=checked]:bg-brand disabled:opacity-50 ${className}`}
        >
            <SwitchPrimitive.Thumb className="block size-5 rounded-pill bg-surface shadow-xs" />
        </SwitchPrimitive.Root>
    );
}
