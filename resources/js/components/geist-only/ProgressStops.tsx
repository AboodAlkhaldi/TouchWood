import { cn } from 'cn';
import { Progress } from '@/components/ui/progress';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

/*
| Geist's Progress "with stops" (frontend.md §1.11: shadcn's Progress has no stops; the bar itself
| stays shadcn's), built from Geist's own page (its "With Stops" example, read 2026-10-03): each
| stop is a notch through the bar at its value - a dark line beside a light one - that can be
| focused and names itself in a tooltip.
|
| Geist's rules, from that page:
| - `max` is the real ceiling (three steps, not 100); the share is worked out here;
| - stops are for genuine multi-stage work, and the stage is said next to the bar
|   ("Step 2 of 3 · Role") - a stop with no label is noise, so every stop has one;
| - role="progressbar" with its values, an accessible name, and each stop its own aria-label.
|
| shadcn's bar takes its value as a share of 100 and fills from the left; here it is given the
| share, says the share and the stage to a screen reader (aria-valuenow, aria-valuetext), and is
| mirrored on an Arabic page so it fills from the right, as the stops are placed from the start
| edge.
*/

export type ProgressStop = {
    value: number;
    /** What the stop marks, shown on hover and focus ("Profile"). */
    tooltip: string;
    /** For a screen reader, when the tooltip alone says too little. */
    ariaLabel?: string;
};

type Props = {
    value: number;
    max: number;
    stops: ProgressStop[];
    /** The bar's name ("Invitation"). */
    label: string;
    /** The stage, as the text next to the bar says it ("Step 2 of 3 · Role"). */
    valueText: string;
    className?: string;
};

export function ProgressStops({ value, max, stops, label, valueText, className }: Props) {
    const share = (amount: number) => (max <= 0 ? 0 : Math.min(100, Math.max(0, (amount / max) * 100)));

    return (
        <div className={cn('relative h-2.5 w-full', className)} data-test="progress">
            <Progress
                value={share(value)}
                max={100}
                // shadcn's Progress keeps `value` for its bar and never hands it to Radix's root, so
                // the root would say nothing to a screen reader (the review of batch A). Given here,
                // they reach the root through shadcn's own prop spread, over Radix's empty ones.
                aria-valuenow={Math.round(share(value))}
                aria-valuetext={valueText}
                aria-label={label}
                className="h-2.5 rounded-[var(--tw-radius-sm)] bg-surface-sunken shadow-[inset_0_0_0_1px_var(--tw-line)] rtl:-scale-x-100 [&>[data-slot=progress-indicator]]:bg-brand"
            />
            {stops.map((stop) => (
                <Tooltip key={stop.value}>
                    <TooltipTrigger asChild>
                        <span
                            tabIndex={0}
                            role="img"
                            aria-label={stop.ariaLabel ?? stop.tooltip}
                            className="absolute inset-y-0 z-10 flex w-3.5 justify-center rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            style={{ insetInlineStart: `calc(${share(stop.value)}% - 7px)` }}
                            data-test={`stop-${stop.value}`}
                        >
                            <span aria-hidden="true" className="h-full w-px bg-ink-subtle" />
                            <span aria-hidden="true" className="h-full w-px bg-surface" />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>{stop.tooltip}</TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}
