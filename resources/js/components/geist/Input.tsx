import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { ChevronDown } from 'lucide-react';
import { cx } from './cx';

/*
| Geist's Label, Input, Textarea and Select (frontend.md 1.10).
|
| One shape for every field: a Title Case noun above, the control, then one sentence under it - the
| helper text before anything goes wrong, the error in its place once it does. Both are tied to the
| control with aria-describedby, and an error marks it aria-invalid, so a screen reader hears what a
| sighted person sees. Validation runs on the server and comes back per field (1.7); the message
| names the field and the rule ("Email address is required.").
|
| A placeholder is an example value ("name@example.com"), never an instruction. Nothing here wraps a
| field in a Tooltip: help goes in the helper text or on a sibling icon button.
|
| Every field may shrink (min-w-0): an <input> has a natural width of about twenty characters, and
| two fields side by side in a narrow card pushed the second one out of it (found in the Geist move,
| 2026-10-02, on the registration form).
*/

export type FieldSize = 'small' | 'medium' | 'large';

const HEIGHT: Record<FieldSize, string> = { small: 'h-8', medium: 'h-9', large: 'h-10' };

const BOX = 'w-full min-w-0 rounded-[var(--tw-radius)] text-copy-14 transition-shadow placeholder:text-ink-subtle';

/*
| One edge per state, never two on one element: with the normal ring and the error ring both on
| a field, the stylesheet's order decided, and an invalid field kept its grey edge (the review of the
| move). Hover lightens only a field that is not being typed in.
*/
const RING =
    'bg-surface text-ink shadow-[0_0_0_1px_var(--tw-line-strong)] hover:not-focus-within:shadow-[0_0_0_1px_var(--tw-ink-subtle)] focus-within:shadow-[0_0_0_1px_var(--tw-brand)]';
const RING_ERROR = 'bg-surface text-ink shadow-[0_0_0_1px_var(--tw-bad)]';
const RING_OFF = 'cursor-not-allowed bg-surface-sunken text-ink-subtle shadow-[0_0_0_1px_var(--tw-line-strong)]';

function ring(invalid: boolean, disabled: boolean | undefined): string {
    return disabled ? RING_OFF : invalid ? RING_ERROR : RING;
}

export function Label({ htmlFor, children, className }: { htmlFor?: string; children: ReactNode; className?: string }) {
    return (
        <label htmlFor={htmlFor} className={cx('block text-label-14 font-medium text-ink', className)}>
            {children}
        </label>
    );
}

/** The sentence under a field: its helper, or its error once there is one. */
export function FieldMessage({ id, helper, error }: { id: string; helper?: ReactNode; error?: string }) {
    if (error !== undefined && error !== '') {
        return (
            <p id={`${id}-error`} role="alert" className="text-copy-13 text-bad">
                {error}
            </p>
        );
    }

    return helper === undefined ? null : (
        <p id={`${id}-helper`} className="text-copy-13 text-ink-muted">
            {helper}
        </p>
    );
}

/** The aria-describedby a control needs for the message under it. */
export function describedBy(id: string, helper?: ReactNode, error?: string): string | undefined {
    if (error !== undefined && error !== '') {
        return `${id}-error`;
    }

    return helper === undefined ? undefined : `${id}-helper`;
}

type Shared = {
    id: string;
    label?: ReactNode;
    helper?: ReactNode;
    error?: string;
    size?: FieldSize;
    className?: string;
};

type InputProps = Shared &
    Omit<InputHTMLAttributes<HTMLInputElement>, 'size' | 'prefix' | 'className'> & {
        prefix?: ReactNode;
        suffix?: ReactNode;
        /** Classes for the typed text alone - `tw-figure` for codes and numbers - not the label. */
        inputClassName?: string;
    };

export function Input({ id, label, helper, error, size = 'medium', className, prefix, suffix, disabled, inputClassName, ...rest }: InputProps) {
    const invalid = error !== undefined && error !== '';

    return (
        <div className={cx('grid min-w-0 gap-1.5', className)}>
            {label === undefined ? null : <Label htmlFor={id}>{label}</Label>}
            <div className={cx('flex items-center gap-2 px-3', HEIGHT[size], BOX, ring(invalid, disabled))}>
                {prefix === undefined ? null : <span className="flex shrink-0 items-center text-ink-muted">{prefix}</span>}
                <input
                    {...rest}
                    id={id}
                    disabled={disabled}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy(id, helper, error)}
                    className={cx('h-full min-w-0 flex-1 bg-transparent outline-none placeholder:text-ink-subtle disabled:cursor-not-allowed', inputClassName)}
                />
                {suffix === undefined ? null : <span className="flex shrink-0 items-center text-ink-muted">{suffix}</span>}
            </div>
            <FieldMessage id={id} helper={helper} error={error} />
        </div>
    );
}

type TextareaProps = Shared & Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'className'>;

export function Textarea({ id, label, helper, error, className, rows = 4, disabled, ...rest }: TextareaProps) {
    const invalid = error !== undefined && error !== '';

    return (
        <div className={cx('grid min-w-0 gap-1.5', className)}>
            {label === undefined ? null : <Label htmlFor={id}>{label}</Label>}
            <textarea
                {...rest}
                id={id}
                rows={rows}
                disabled={disabled}
                aria-invalid={invalid || undefined}
                aria-describedby={describedBy(id, helper, error)}
                className={cx('block resize-y px-3 py-2 outline-none', BOX, ring(invalid, disabled))}
            />
            <FieldMessage id={id} helper={helper} error={error} />
        </div>
    );
}

type SelectProps = Shared &
    Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size' | 'className'> & {
        /** Action-oriented ("Select a store"), shown while nothing is chosen. */
        placeholder?: string;
    };

export function Select({ id, label, helper, error, size = 'medium', className, placeholder, children, disabled, ...rest }: SelectProps) {
    const invalid = error !== undefined && error !== '';

    return (
        <div className={cx('grid min-w-0 gap-1.5', className)}>
            {label === undefined ? null : <Label htmlFor={id}>{label}</Label>}
            <div className="relative min-w-0">
                <select
                    {...rest}
                    id={id}
                    disabled={disabled}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy(id, helper, error)}
                    className={cx('appearance-none pe-9 ps-3 outline-none', HEIGHT[size], BOX, ring(invalid, disabled))}
                >
                    {placeholder === undefined ? null : (
                        <option value="" disabled>
                            {placeholder}
                        </option>
                    )}
                    {children}
                </select>
                <ChevronDown aria-hidden="true" className="pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted" />
            </div>
            <FieldMessage id={id} helper={helper} error={error} />
        </div>
    );
}
