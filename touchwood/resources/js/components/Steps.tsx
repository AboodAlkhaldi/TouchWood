import { Circle, CircleCheck, CircleDot } from 'lucide-react';
import { cn } from '@/lib/cn';

/*
| A form's steps across its top, drawn as the company page's tracking steps are (b2b.md amendment
| 26(b); the owner, 2026-10-06: "as we created the companies tracking steps design, much easier, not
| a line as now"): a circle per step - done, the current one, still to come - its name beside it, and
| a line to the next. Neither Geist nor shadcn has a stepper; this is a list and Lucide's circles.
|
| The circles say nothing to a screen reader; each step's state is said in words, and the current
| one carries aria-current="step".
*/

type Props = {
    /** The steps' names, in order. */
    names: string[];
    /** The current step, from 1. */
    current: number;
    /** The list's name, read aloud: what the steps are of. */
    label: string;
    /** "Done" and "Not yet", in the page's language, for a screen reader. */
    doneLabel: string;
    upcomingLabel: string;
};

export function Steps({ names, current, label, doneLabel, upcomingLabel }: Props) {
    return (
        <ol aria-label={label} className="flex flex-wrap items-center gap-x-3 gap-y-2" data-test="steps">
            {names.map((name, index) => {
                const step = index + 1;
                const state = step < current ? 'done' : step === current ? 'current' : 'upcoming';
                const Icon = state === 'done' ? CircleCheck : state === 'current' ? CircleDot : Circle;

                return (
                    <li key={name} className="flex items-center gap-3" data-test={`step-${step}`} data-state={state} aria-current={state === 'current' ? 'step' : undefined}>
                        <span className="flex items-center gap-2">
                            <Icon aria-hidden="true" className={cn('size-5 shrink-0', state === 'upcoming' ? 'text-ink-subtle' : 'text-brand')} />
                            <span className={cn('text-label-14', state === 'current' ? 'font-medium text-ink' : state === 'done' ? 'text-ink' : 'text-ink-subtle')}>
                                {name}
                                {state === 'current' ? null : <span className="sr-only">{` (${state === 'done' ? doneLabel : upcomingLabel})`}</span>}
                            </span>
                        </span>
                        {step === names.length ? null : (
                            <span aria-hidden="true" className={cn('h-px w-8 sm:w-12', state === 'done' ? 'bg-brand' : 'bg-line')} />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
