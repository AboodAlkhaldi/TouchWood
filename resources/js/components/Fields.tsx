import type { ComponentProps, ReactNode } from 'react';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';

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
*/

type Common = {
    id: string;
    label: ReactNode;
    /** One sentence under the field. */
    helper?: ReactNode;
    error?: string;
    className?: string;
};

function describedBy(id: string, helper?: ReactNode, error?: string): string | undefined {
    const ids = [helper === undefined || helper === null ? null : `${id}-helper`, error ? `${id}-error` : null].filter(Boolean);

    return ids.length === 0 ? undefined : ids.join(' ');
}

function Messages({ id, helper, error }: { id: string; helper?: ReactNode; error?: string }) {
    return (
        <>
            {helper === undefined || helper === null ? null : <FieldDescription id={`${id}-helper`}>{helper}</FieldDescription>}
            {error ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
        </>
    );
}

export function TextField({
    id,
    label,
    helper,
    error,
    className,
    inputClassName,
    ...input
}: Common & Omit<ComponentProps<typeof Input>, 'id' | 'className'> & { /** For the input itself: `tw-figure` on a number. */ inputClassName?: string }) {
    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Input {...input} id={id} className={inputClassName} aria-invalid={error ? true : undefined} aria-describedby={describedBy(id, helper, error)} />
            <Messages id={id} helper={helper} error={error} />
        </Field>
    );
}

export function TextareaField({ id, label, helper, error, className, ...input }: Common & Omit<ComponentProps<typeof Textarea>, 'id' | 'className'>) {
    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Textarea {...input} id={id} aria-invalid={error ? true : undefined} aria-describedby={describedBy(id, helper, error)} />
            <Messages id={id} helper={helper} error={error} />
        </Field>
    );
}

export function SelectField({ id, label, helper, error, className, children, ...select }: Common & Omit<ComponentProps<typeof NativeSelect>, 'id' | 'className'>) {
    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            {/* shadcn's NativeSelect is as wide as its text; a form's select is as wide as its field. */}
            <NativeSelect
                {...select}
                id={id}
                className="w-full"
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(id, helper, error)}
            >
                {children}
            </NativeSelect>
            <Messages id={id} helper={helper} error={error} />
        </Field>
    );
}
