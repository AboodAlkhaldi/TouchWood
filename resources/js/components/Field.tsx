import type { ReactNode } from 'react';
import { FieldMessage, Label } from '@/components/geist';

/*
| One field around a control Geist has no field for - a picker, a group of choices built for one
| screen (frontend.md §2.1, 1.10).
|
| A plain text, select or password field is Geist's own Input, Select or PasswordInput, which carry
| their label, helper and error themselves; this is for everything else, so a composite control
| still gets Geist's shape: a Title Case label above, one sentence under it - the helper, or the
| error in its place.
*/

type Props = {
    id: string;
    label: string;
    error?: string;
    /** One sentence under the label: the rule, in words. */
    hint?: string;
    children: ReactNode;
};

export function Field({ id, label, error, hint, children }: Props) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {/* aria-describedby is set by the caller on the control itself; passing it down here
                would mean cloning the child, which hides where the attribute came from. */}
            {children}
            <FieldMessage id={id} helper={hint} error={error} />
        </div>
    );
}
