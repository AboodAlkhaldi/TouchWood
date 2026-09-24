import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';

/*
| One field, one label above it, one message under it (frontend.md §2.1).
|
| Every form in the system is built from this, so a validation message always appears in the same
| place and reads the same way. The message under a field is the field's own; a business error that
| belongs to no field goes at the top of the form, and also as a toast (§2.1).
*/

type Props = {
    id: string;
    label: string;
    error?: string;
    /** Shown under the label, before the person types: the rule, in words. */
    hint?: string;
    children: ReactNode;
};

export function Field({ id, label, error, hint, children }: Props) {
    const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null]
        .filter(Boolean)
        .join(' ');

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id} className="text-ink">
                {label}
            </Label>

            {hint ? (
                <p id={`${id}-hint`} className="text-xs text-ink-muted">
                    {hint}
                </p>
            ) : null}

            {/* aria-describedby is set by the caller on the control itself; passing it down here
                would mean cloning the child, which hides where the attribute came from. */}
            <div data-described-by={describedBy || undefined}>{children}</div>

            {error ? (
                <p id={`${id}-error`} role="alert" className="text-xs text-bad">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
