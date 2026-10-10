import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { problemOf, type Rules, sentenceOf, subjectOf } from '@/lib/checks';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| A form's boxes checked as they are typed (frontend.md §1.7), for the shared fields
| (components/Fields.tsx) and any control that takes a `BoxCheck`. The rules and their words are
| lib/checks.ts; this is when each is said, and what it does to Save:
|
| - **as it is typed**: a box says what is wrong the moment it is typed in - a letter in a number box,
|   out of range, too long - and once it is left;
| - **"required" waits** until the box was typed in or left, or Save pressed: a fresh form says
|   nothing;
| - **Save is out of reach** (`reason`) while a box the person typed in or left is wrong, its reason
|   that box's own sentence; an untouched required box does not lock it -
| - **pressing Save** (`submit`) sends nothing while any box is wrong: every box then says its rule,
|   and the focus goes to the first wrong one.
|
| A refusal the server sent for a box belongs to the value it refused (b2b.md amendment 17): typing
| another value takes it away, and the box's own check speaks again. The server still checks
| everything; this saves a round trip, never replaces one.
*/

export type Box = {
    /** The control's id: the focus goes there when Save finds it wrong. */
    id: string;
    /** The box's label, named as the sentence's subject. */
    label: string;
    value: string;
    rules: Rules;
    /** The words naming it in a sentence, when its label will not do ("Note (Optional)"). */
    subject?: string;
    /** Not checked while it is switched off - a period when "For Life" is ticked. */
    off?: boolean;
};

/** What one box needs from its form's checks. */
export type BoxCheck = {
    /** What the box says is wrong, or undefined: its own check once shown, else the server's refusal. */
    message: string | undefined;
    /** Whether that message is the box's own, said as it is typed - announced politely, not as an alert. */
    typed: boolean;
    required: boolean;
    /** Call as the person types in it. */
    onType: () => void;
    /** Call as the person leaves it. */
    onLeave: () => void;
};

export type Checks = {
    /** One box's check; `refusal` is the server's answer for it, if any. */
    box: (id: string, refusal?: string) => BoxCheck;
    /** Why Save is out of reach - the first wrong box the person has typed in or left - or undefined. */
    reason: string | undefined;
    /** Sends when every box passes; otherwise says every box's rule and focuses the first wrong one. */
    submit: (send: () => void) => void;
};

/**
 * @param fresh  Anything that changes when the form starts again - a dialog that stays on the page
 *   opening or closing (`open`): the boxes it said were wrong before are forgotten, as on a new form.
 */
export function useChecks(boxes: Box[], fresh?: unknown): Checks {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const [shown, setShown] = useState<ReadonlySet<string>>(() => new Set());
    // What each box held when the form was last sent: the server's refusal is about that value, and
    // stands only while the box still holds it.
    const sent = useRef(new Map<string, string>());
    // A box left because Save is being pressed: said once that press has landed, not before.
    const pressing = useRef(false);
    const left = useRef<string[]>([]);
    const [was, setWas] = useState(() => fresh);

    if (was !== fresh) {
        // Set while rendering, as React's docs do for state that follows a prop: no frame shows the
        // boxes of the form before. What was sent is kept: a refusal still belongs to its value.
        setWas(() => fresh);
        setShown(new Set());
    }

    useEffect(() => {
        let late: ReturnType<typeof setTimeout> | undefined;
        // The boxes left during the press, said now.
        const said = () => {
            const ids = left.current;
            left.current = [];
            setShown((was) => (ids.every((id) => was.has(id)) ? was : new Set([...was, ...ids])));
        };
        const down = () => {
            clearTimeout(late);
            pressing.current = true;
        };
        // Pressing Save takes the focus from the box first, and a box left wrong takes Save out of
        // reach - under the pointer, before the press could land, and Save would then say nothing at
        // all (found by the browser test). So a box left by a press is said once its click has run:
        // after the click's own handlers (this listener runs first, in the capture phase, and waits
        // its turn). On a touch screen the focus moves after the finger lifts, so the press ends at
        // the click, not at pointerup.
        const click = () => {
            clearTimeout(late);
            pressing.current = false;
            setTimeout(said, 0);
        };
        // A press that makes no click - dragged off, or cancelled - ends a moment after it is let go.
        const up = () => {
            clearTimeout(late);
            late = setTimeout(() => {
                pressing.current = false;
                said();
            }, 500);
        };

        document.addEventListener('pointerdown', down, true);
        document.addEventListener('click', click, true);
        document.addEventListener('pointerup', up, true);
        document.addEventListener('pointercancel', up, true);

        return () => {
            clearTimeout(late);
            document.removeEventListener('pointerdown', down, true);
            document.removeEventListener('click', click, true);
            document.removeEventListener('pointerup', up, true);
            document.removeEventListener('pointercancel', up, true);
        };
    }, []);

    const problems = new Map<string, string>();

    for (const box of boxes) {
        const problem = box.off === true ? null : problemOf(box.value, box.rules);

        if (problem !== null) {
            problems.set(box.id, sentenceOf(problem, box.subject ?? subjectOf(box.label, locale), t, locale));
        }
    }

    const show = (id: string) => setShown((was) => (was.has(id) ? was : new Set(was).add(id)));
    const first = boxes.find((box) => shown.has(box.id) && problems.has(box.id));

    return {
        box: (id, refusal) => {
            const box = boxes.find((each) => each.id === id);
            const own = shown.has(id) ? problems.get(id) : undefined;
            const held = sent.current.get(id);
            // A refusal the page arrived with, before anything was sent from it, stands as it is.
            const server = refusal !== undefined && refusal !== '' && (held === undefined || held === box?.value) ? refusal : undefined;

            return {
                message: own ?? server,
                typed: own !== undefined,
                required: box?.rules.required === true,
                onType: () => show(id),
                onLeave: () => {
                    if (pressing.current) {
                        left.current.push(id);
                    } else {
                        show(id);
                    }
                },
            };
        },
        reason: first === undefined ? undefined : problems.get(first.id),
        submit: (send) => {
            const wrong = boxes.filter((box) => problems.has(box.id));
            // A box left by this very press is not said afterwards: the send settles every box, and
            // a refusal says all of them anyway.
            left.current = [];

            if (wrong.length === 0) {
                sent.current = new Map(boxes.map((box) => [box.id, box.value]));
                // Every box passes as it goes, so nothing shown is lost - and a form emptied once it
                // is saved (a password changed, a number confirmed) starts fresh, not "required".
                setShown(new Set());
                send();

                return;
            }

            setShown(new Set(boxes.map((box) => box.id)));
            document.getElementById(wrong[0]?.id ?? '')?.focus();
        },
    };
}
