import type { ComponentProps, FocusEvent, ReactNode } from 'react';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { BoxCheck } from '@/lib/use-checks';

/*
| One labelled field, as shadcn's `field` examples write it - Field, FieldLabel, the control,
| FieldDescription, FieldError - so a form's fields are not each wired by hand (frontend.md §1.11).
|
| Geist's rules for them (Input, Select, Textarea):
| - a short Title Case label, always visible and tied to the control;
| - helper text is one sentence under the field, tied with aria-describedby;
| - the error replaces nothing: it is said under the field, names the field and the rule, and the
|   field says it is invalid (aria-invalid), which paints shadcn's red edge - the only colour that
|   changes (owner, 2026-10-02, round 3 of the "neither" pieces, #11).
|
| Given a `check` (lib/use-checks.ts), the field checks itself as it is typed (frontend.md §1.7): it
| tells the check when it is typed in and when it is left, and says the check's message - its own, or
| the server's refusal of the value it holds - in place of `error`. Its own message is the field's
| description, as the server's is, and is announced politely as it changes, from a line that is
| always there, rather than as an alert at every keystroke (§6). A required field with a check is
| marked for a screen reader only (aria-required): the browser's own "required" bubble, in the
| browser's language, would stop Save before the form could say the rule in its own words.
*/

type Common = {
    id: string;
    label: ReactNode;
    /** One sentence under the field. */
    helper?: ReactNode;
    error?: string;
    className?: string;
    /** Another element that describes the field too - a note above the form, read before its helper. */
    alsoDescribedBy?: string;
    /** The field's check as it is typed, from its form's useChecks. */
    check?: BoxCheck;
};

export function describedBy(id: string, helper?: ReactNode, error?: string, also?: string): string | undefined {
    const ids = [also ?? null, helper === undefined || helper === null ? null : `${id}-helper`, error ? `${id}-error` : null].filter(Boolean);

    return ids.length === 0 ? undefined : ids.join(' ');
}

/** Under a field: its helper, what is wrong, and - for a checked field - the line a screen reader hears. */
export function Messages({ id, helper, error, check }: { id: string; helper?: ReactNode; error?: string; check?: BoxCheck }) {
    const typed = check?.typed === true;

    return (
        <>
            {helper === undefined || helper === null ? null : <FieldDescription id={`${id}-helper`}>{helper}</FieldDescription>}
            {/* The server's refusal arrives once, as an alert; a check said while typing is not one. */}
            {error ? (
                <FieldError id={`${id}-error`} role={typed ? undefined : 'alert'}>
                    {error}
                </FieldError>
            ) : null}
            {/* Always there, so a screen reader hears each change of the check, politely. */}
            {check === undefined ? null : (
                <span className="sr-only" aria-live="polite">
                    {typed ? error : ''}
                </span>
            )}
        </>
    );
}

/** What a control does with its field's check: says it was typed in, or left. */
export function checked<E extends HTMLElement>(
    check: BoxCheck | undefined,
    control: { required?: boolean; onChange?: (event: never) => void; onBlur?: (event: FocusEvent<E>) => void },
) {
    if (check === undefined) {
        return {};
    }

    return {
        required: undefined,
        'aria-required': check.required || control.required ? true : undefined,
        onBlur: (event: FocusEvent<E>) => {
            control.onBlur?.(event);
            check.onLeave();
        },
    };
}

export function TextField({
    id,
    label,
    helper,
    error,
    className,
    inputClassName,
    alsoDescribedBy,
    check,
    ...input
}: Common & Omit<ComponentProps<typeof Input>, 'id' | 'className'> & { /** For the input itself: `tw-figure` on a number. */ inputClassName?: string }) {
    const shown = check === undefined ? error : check.message;

    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Input
                {...input}
                {...checked(check, input)}
                onChange={(event) => {
                    input.onChange?.(event);
                    check?.onType();
                }}
                id={id}
                className={inputClassName}
                aria-invalid={shown ? true : undefined}
                aria-describedby={describedBy(id, helper, shown, alsoDescribedBy)}
            />
            <Messages id={id} helper={helper} error={shown} check={check} />
        </Field>
    );
}

export function TextareaField({ id, label, helper, error, className, alsoDescribedBy, check, ...input }: Common & Omit<ComponentProps<typeof Textarea>, 'id' | 'className'>) {
    const shown = check === undefined ? error : check.message;

    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Textarea
                {...input}
                {...checked(check, input)}
                onChange={(event) => {
                    input.onChange?.(event);
                    check?.onType();
                }}
                id={id}
                aria-invalid={shown ? true : undefined}
                aria-describedby={describedBy(id, helper, shown, alsoDescribedBy)}
            />
            <Messages id={id} helper={helper} error={shown} check={check} />
        </Field>
    );
}

export function SelectField({ id, label, helper, error, className, alsoDescribedBy, check, children, ...select }: Common & Omit<ComponentProps<typeof NativeSelect>, 'id' | 'className'>) {
    const shown = check === undefined ? error : check.message;

    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            {/* As wide as its field: shadcn's Field stretches its children (`[&>*]:w-full`), the
                select's own wrapper included, and the select fills that. */}
            <NativeSelect
                {...select}
                {...checked(check, select)}
                onChange={(event) => {
                    select.onChange?.(event);
                    check?.onType();
                }}
                id={id}
                aria-invalid={shown ? true : undefined}
                aria-describedby={describedBy(id, helper, shown, alsoDescribedBy)}
            >
                {children}
            </NativeSelect>
            <Messages id={id} helper={helper} error={shown} check={check} />
        </Field>
    );
}
