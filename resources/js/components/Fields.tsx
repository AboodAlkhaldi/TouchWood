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
    /** Another element that describes the field too - a note above the form, read before its helper. */
    alsoDescribedBy?: string;
};

function describedBy(id: string, helper?: ReactNode, error?: string, also?: string): string | undefined {
    const ids = [also ?? null, helper === undefined || helper === null ? null : `${id}-helper`, error ? `${id}-error` : null].filter(Boolean);

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
    alsoDescribedBy,
    ...input
}: Common & Omit<ComponentProps<typeof Input>, 'id' | 'className'> & { /** For the input itself: `tw-figure` on a number. */ inputClassName?: string }) {
    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Input {...input} id={id} className={inputClassName} aria-invalid={error ? true : undefined} aria-describedby={describedBy(id, helper, error, alsoDescribedBy)} />
            <Messages id={id} helper={helper} error={error} />
        </Field>
    );
}

export function TextareaField({ id, label, helper, error, className, alsoDescribedBy, ...input }: Common & Omit<ComponentProps<typeof Textarea>, 'id' | 'className'>) {
    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <Textarea {...input} id={id} aria-invalid={error ? true : undefined} aria-describedby={describedBy(id, helper, error, alsoDescribedBy)} />
            <Messages id={id} helper={helper} error={error} />
        </Field>
    );
}

export function SelectField({ id, label, helper, error, className, alsoDescribedBy, children, ...select }: Common & Omit<ComponentProps<typeof NativeSelect>, 'id' | 'className'>) {
    return (
        <Field className={className}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            {/* As wide as its field: shadcn's Field stretches its children (`[&>*]:w-full`), the
                select's own wrapper included, and the select fills that. */}
            <NativeSelect
                {...select}
                id={id}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy(id, helper, error, alsoDescribedBy)}
            >
                {children}
            </NativeSelect>
            <Messages id={id} helper={helper} error={error} />
        </Field>
    );
}
