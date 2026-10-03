import type { ReactNode } from 'react';
import { Check, Minus } from 'lucide-react';
import { Checkbox as CheckboxPrimitive, RadioGroup as RadioPrimitive, Switch as SwitchPrimitive } from 'radix-ui';
import { cx } from './cx';
import { Tooltip } from './Tooltip';

/*
| Geist's Checkbox, RadioGroup, Toggle and Switch (frontend.md 1.10) - the controls that choose.
|
| Which one: a Checkbox picks several from a list, or affirms a sentence ("I agree to the Terms of
| Service."); a RadioGroup picks one of two to six; a Toggle is one setting that takes effect the
| moment it flips; a Switch is Geist's segmented control between two or three views of the same
| thing. A disabled choice always says why, in a tooltip - a greyed box with no reason reads as a bug.
|
| Radix gives each its role, its keyboard handling and its checked state. Thumbs and marks move by
| flex alignment, never a translate, so Arabic pages need no mirrored rule.
*/

const BOX_FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand';

/**
 * A control and the reason it can't be used, in a wrapper of its own (found in the Geist move,
 * 2026-10-02): the tooltip's trigger writes its own data-state, which on the Radix control itself
 * overwrote "checked" and drew a ticked, locked box as unticked; and the wrapper is always there,
 * so gaining or losing a reason never re-mounts the control.
 *
 * A control with a reason is aria-disabled, never disabled: it keeps its tab stop, a screen reader
 * still hears its name and its state, and its focus opens the tooltip through the wrapper (focus
 * events bubble). A natively disabled control dropped out of the tab order with its reason, and a
 * focusable wrapper around it had no name at all (the review of the move).
 */
function explained(node: ReactNode, reason?: string): ReactNode {
    return (
        <Tooltip text={reason}>
            <span className="inline-flex shrink-0">{node}</span>
        </Tooltip>
    );
}

/** The edge of an unticked box or an off toggle: ink-subtle, 3.12:1 on a card (line-strong was 1.59:1). */
const EDGE = 'shadow-[0_0_0_1px_var(--tw-ink-subtle)]';
const UNAVAILABLE = 'aria-disabled:cursor-not-allowed aria-disabled:opacity-50';

type CheckboxProps = {
    id: string;
    checked: boolean | 'indeterminate';
    onChange: (checked: boolean) => void;
    children?: ReactNode;
    disabledReason?: string;
    'aria-label'?: string;
    /** The id of the error or helper under it, so a screen reader hears it with the box. */
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
    name?: string;
    value?: string;
    'data-test'?: string;
};

export function Checkbox({ id, checked, onChange, children, disabledReason, ...rest }: CheckboxProps) {
    const disabled = disabledReason !== undefined;

    return (
        <div className="inline-flex items-start gap-2">
            {explained(
                <CheckboxPrimitive.Root
                    {...rest}
                    id={id}
                    checked={checked}
                    aria-disabled={disabled || undefined}
                    onCheckedChange={(next) => (disabled ? undefined : onChange(next === true))}
                    className={cx(
                        'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-[calc(var(--tw-radius-sm)_-_2px)] bg-surface text-ink-on-brand transition-colors data-[state=checked]:bg-brand data-[state=checked]:shadow-none data-[state=indeterminate]:bg-brand data-[state=indeterminate]:shadow-none',
                        EDGE,
                        UNAVAILABLE,
                        BOX_FOCUS,
                    )}
                >
                    <CheckboxPrimitive.Indicator>
                        {checked === 'indeterminate' ? <Minus className="size-3" strokeWidth={3} /> : <Check className="size-3" strokeWidth={3} />}
                    </CheckboxPrimitive.Indicator>
                </CheckboxPrimitive.Root>,
                disabledReason,
            )}
            {children === undefined ? null : (
                <label htmlFor={id} className={cx('text-label-14 text-ink', disabled && 'text-ink-subtle')}>
                    {children}
                </label>
            )}
        </div>
    );
}

export type RadioOption = { value: string; label: ReactNode; disabledReason?: string };

type RadioGroupProps = {
    name: string;
    /** The group's Title Case noun, read before each option. */
    legend: ReactNode;
    value: string;
    onChange: (value: string) => void;
    options: RadioOption[];
    error?: string;
};

export function RadioGroup({ name, legend, value, onChange, options, error }: RadioGroupProps) {
    const locked = new Set(options.filter((option) => option.disabledReason !== undefined).map((option) => option.value));

    return (
        <fieldset className="grid gap-2" aria-describedby={error === undefined || error === '' ? undefined : `${name}-error`}>
            <legend className="mb-1 text-label-14 font-medium text-ink">{legend}</legend>
            <RadioPrimitive.Root
                name={name}
                value={value}
                onValueChange={(next) => (locked.has(next) ? undefined : onChange(next))}
                className="grid gap-2"
            >
                {options.map((option) => {
                    const id = `${name}-${option.value}`;

                    return (
                        <div key={option.value} className="inline-flex items-center gap-2">
                            {explained(
                                <RadioPrimitive.Item
                                    id={id}
                                    value={option.value}
                                    aria-disabled={option.disabledReason === undefined ? undefined : true}
                                    className={cx(
                                        'flex size-4 shrink-0 items-center justify-center rounded-[var(--tw-radius-pill)] bg-surface data-[state=checked]:shadow-[0_0_0_1px_var(--tw-brand)]',
                                        EDGE,
                                        UNAVAILABLE,
                                        BOX_FOCUS,
                                    )}
                                >
                                    <RadioPrimitive.Indicator className="block size-2 rounded-[var(--tw-radius-pill)] bg-brand" />
                                </RadioPrimitive.Item>,
                                option.disabledReason,
                            )}
                            <label htmlFor={id} className="text-label-14 text-ink">
                                {option.label}
                            </label>
                        </div>
                    );
                })}
            </RadioPrimitive.Root>
            {error === undefined || error === '' ? null : (
                <p id={`${name}-error`} role="alert" className="text-copy-13 text-bad">
                    {error}
                </p>
            )}
        </fieldset>
    );
}

type ToggleProps = {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    /** Title Case, what is true when ON ("Password Protection"), never "Enable …". */
    children?: ReactNode;
    /** One sentence about ON only. */
    description?: ReactNode;
    disabledReason?: string;
    'aria-label'?: string;
    /** An error's id, read with the toggle; the description's own id is added to it. */
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
    'data-test'?: string;
};

export function Toggle({ id, checked, onChange, children, description, disabledReason, ...rest }: ToggleProps) {
    const disabled = disabledReason !== undefined;
    const described = [rest['aria-describedby'], description === undefined ? undefined : `${id}-description`].filter(Boolean).join(' ');

    return (
        <div className="inline-flex items-start gap-3">
            {explained(
                // 24 by 44 pixels, the touch target frontend.md §6 asks for (Geist's own is smaller).
                <SwitchPrimitive.Root
                    {...rest}
                    id={id}
                    checked={checked}
                    aria-disabled={disabled || undefined}
                    onCheckedChange={(next) => (disabled ? undefined : onChange(next))}
                    aria-describedby={described === '' ? undefined : described}
                    className={cx(
                        'inline-flex h-6 w-11 shrink-0 items-center justify-start rounded-[var(--tw-radius-pill)] bg-ink-subtle p-0.5 transition-colors data-[state=checked]:justify-end data-[state=checked]:bg-brand',
                        UNAVAILABLE,
                        BOX_FOCUS,
                    )}
                >
                    <SwitchPrimitive.Thumb className="block size-5 rounded-[var(--tw-radius-pill)] bg-surface shadow-[var(--tw-shadow-small)]" />
                </SwitchPrimitive.Root>,
                disabledReason,
            )}
            {children === undefined && description === undefined ? null : (
                <div className="grid gap-0.5">
                    {children === undefined ? null : (
                        <label htmlFor={id} className={cx('text-label-14 text-ink', disabled && 'text-ink-subtle')}>
                            {children}
                        </label>
                    )}
                    {description === undefined ? null : (
                        <p id={`${id}-description`} className="text-copy-13 text-ink-muted">
                            {description}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

export type SwitchOption = { value: string; label: string; icon?: ReactNode; 'data-test'?: string };

type SwitchProps = {
    name: string;
    value: string;
    onChange: (value: string) => void;
    options: SwitchOption[];
    size?: 'small' | 'medium';
    'aria-label': string;
};

/** Geist's Switch: two or three views of one surface, one word each, radio semantics. */
export function Switch({ name, value, onChange, options, size = 'medium', ...rest }: SwitchProps) {
    return (
        <RadioPrimitive.Root
            {...rest}
            name={name}
            value={value}
            onValueChange={onChange}
            orientation="horizontal"
            className="inline-flex items-center gap-0.5 rounded-[var(--tw-radius)] bg-surface-sunken p-0.5 shadow-[0_0_0_1px_var(--tw-line)]"
        >
            {options.map((option) => {
                const item = (
                    <RadioPrimitive.Item
                        key={option.value}
                        value={option.value}
                        data-test={option['data-test']}
                        aria-label={option.icon === undefined ? undefined : option.label}
                        className={cx(
                            'inline-flex items-center justify-center gap-1.5 rounded-[calc(var(--tw-radius)_-_2px)] px-3 text-button-14 text-ink-muted transition-colors hover:text-ink data-[state=checked]:bg-surface data-[state=checked]:text-ink data-[state=checked]:shadow-[var(--tw-shadow-small)]',
                            size === 'small' ? 'h-7' : 'h-8',
                            BOX_FOCUS,
                        )}
                    >
                        {option.icon ?? option.label}
                    </RadioPrimitive.Item>
                );

                // Wrapped, not the item itself: the tooltip's data-state would overwrite "checked".
                return (
                    <Tooltip key={option.value} text={option.icon === undefined ? undefined : option.label}>
                        <span className="inline-flex">{item}</span>
                    </Tooltip>
                );
            })}
        </RadioPrimitive.Root>
    );
}
