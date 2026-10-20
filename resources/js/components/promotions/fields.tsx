import { Field, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';

/*
| The Marketing dialogs' and forms' shared fields (promotions.md §4.1): one choice among a few - shadcn's
| RadioGroup in a Fieldset, its line under it and its refusal tied to it - and one switch with its
| one-line meaning.
*/

export function Choice({
    name,
    legend,
    value,
    onChange,
    options,
    disabled = false,
    reason,
    error,
}: {
    name: string;
    legend: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    disabled?: boolean;
    /** The line under the choices: why it is out of reach, or what it means. */
    reason?: string;
    error?: string;
}) {
    return (
        <FieldSet>
            <FieldLegend className="text-label-14 text-ink">{legend}</FieldLegend>
            <RadioGroup
                name={name}
                value={value}
                onValueChange={onChange}
                disabled={disabled}
                className="gap-2"
                aria-describedby={[reason ? `${name}-reason` : null, error ? `${name}-error` : null].filter(Boolean).join(' ') || undefined}
                aria-invalid={error ? true : undefined}
            >
                {options.map((option) => (
                    <Field key={option.value} orientation="horizontal" className="gap-2">
                        <RadioGroupItem id={`${name}-${option.value}`} value={option.value} data-test={`${name}-${option.value}`} />
                        <FieldLabel htmlFor={`${name}-${option.value}`} className="text-label-14 font-normal text-ink">
                            {option.label}
                        </FieldLabel>
                    </Field>
                ))}
            </RadioGroup>
            {reason ? (
                <FieldDescription id={`${name}-reason`} className="text-copy-13 text-ink-muted">
                    {reason}
                </FieldDescription>
            ) : null}
            {error ? (
                <p id={`${name}-error`} role="alert" className="text-copy-13 text-bad">
                    {error}
                </p>
            ) : null}
        </FieldSet>
    );
}

export function Toggle({ id, label, hint, checked, onChange, invalid }: { id: string; label: string; hint?: string; checked: boolean; onChange: (on: boolean) => void; invalid?: string }) {
    return (
        <Field orientation="horizontal" className="items-start gap-3">
            <Switch id={id} checked={checked} onCheckedChange={onChange} data-test={id} aria-describedby={invalid} aria-invalid={invalid === undefined ? undefined : true} />
            <div className="grid gap-0.5">
                <FieldLabel htmlFor={id} className="text-label-14 font-normal text-ink">
                    {label}
                </FieldLabel>
                {hint === undefined ? null : <FieldDescription className="text-copy-13 text-ink-muted">{hint}</FieldDescription>}
            </div>
        </Field>
    );
}
