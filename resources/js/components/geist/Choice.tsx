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

function explained(node: ReactNode, reason?: string): ReactNode {
    return reason === undefined ? node : <Tooltip text={reason}>{node}</Tooltip>;
}

type CheckboxProps = {
    id: string;
    checked: boolean | 'indeterminate';
    onChange: (checked: boolean) => void;
    children?: ReactNode;
    disabledReason?: string;
    'aria-label'?: string;
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
                    disabled={disabled}
                    onCheckedChange={(next) => onChange(next === true)}
                    className={cx(
                        'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-[4px] bg-surface text-ink-on-brand shadow-[0_0_0_1px_var(--tw-line-strong)] transition-colors data-[state=checked]:bg-brand data-[state=checked]:shadow-none data-[state=indeterminate]:bg-brand data-[state=indeterminate]:shadow-none disabled:cursor-not-allowed disabled:opacity-50',
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
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-1 text-label-14 font-medium text-ink">{legend}</legend>
            <RadioPrimitive.Root name={name} value={value} onValueChange={onChange} className="grid gap-2">
                {options.map((option) => {
                    const id = `${name}-${option.value}`;

                    return (
                        <div key={option.value} className="inline-flex items-center gap-2">
                            {explained(
                                <RadioPrimitive.Item
                                    id={id}
                                    value={option.value}
                                    disabled={option.disabledReason !== undefined}
                                    className={cx(
                                        'flex size-4 shrink-0 items-center justify-center rounded-full bg-surface shadow-[0_0_0_1px_var(--tw-line-strong)] data-[state=checked]:shadow-[0_0_0_1px_var(--tw-brand)] disabled:cursor-not-allowed disabled:opacity-50',
                                        BOX_FOCUS,
                                    )}
                                >
                                    <RadioPrimitive.Indicator className="block size-2 rounded-full bg-brand" />
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
                <p role="alert" className="text-copy-13 text-bad">
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
    'data-test'?: string;
};

export function Toggle({ id, checked, onChange, children, description, disabledReason, ...rest }: ToggleProps) {
    const disabled = disabledReason !== undefined;

    return (
        <div className="inline-flex items-start gap-3">
            {explained(
                <SwitchPrimitive.Root
                    {...rest}
                    id={id}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={onChange}
                    aria-describedby={description === undefined ? undefined : `${id}-description`}
                    className={cx(
                        'inline-flex h-5 w-9 shrink-0 items-center justify-start rounded-full bg-line-strong p-0.5 transition-colors data-[state=checked]:justify-end data-[state=checked]:bg-brand disabled:cursor-not-allowed disabled:opacity-50',
                        BOX_FOCUS,
                    )}
                >
                    <SwitchPrimitive.Thumb className="block size-4 rounded-full bg-surface shadow-[var(--tw-shadow-small)]" />
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

export type SwitchOption = { value: string; label: string; icon?: ReactNode };

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
                        aria-label={option.icon === undefined ? undefined : option.label}
                        className={cx(
                            'inline-flex items-center justify-center gap-1.5 rounded-[calc(var(--tw-radius)-2px)] px-3 text-button-14 text-ink-muted transition-colors hover:text-ink data-[state=checked]:bg-surface data-[state=checked]:text-ink data-[state=checked]:shadow-[var(--tw-shadow-small)]',
                            size === 'small' ? 'h-7' : 'h-8',
                            BOX_FOCUS,
                        )}
                    >
                        {option.icon ?? option.label}
                    </RadioPrimitive.Item>
                );

                return option.icon === undefined ? item : <Tooltip key={option.value} text={option.label}>{item}</Tooltip>;
            })}
        </RadioPrimitive.Root>
    );
}
