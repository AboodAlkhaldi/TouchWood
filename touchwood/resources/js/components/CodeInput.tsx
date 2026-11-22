import type { ReactNode } from 'react';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { toLatinDigits } from '@/lib/digits';

/*
| An SMS code, one box per digit, on shadcn's InputOTP (frontend.md §1.11: the staff sign-in, the
| staff Change Phone Number dialog and the customer's Change Phone Number). One real input with boxes drawn over it:
| pasting the whole code, the browser filling it from the message (it is marked one-time-code), the
| caret and the arrow keys are all input-otp's own, where the boxes before were each their own input.
|
| How many boxes is a setting, 4 to 8 (Access amendment 22), sent by the server with each page, so
| nothing here assumes six. Arabic-Indic digits are accepted as well as Latin ones - a person typing
| on an Arabic keyboard is entering the same number (§1.8) - and are turned into Latin ones where
| they are typed: the code is compared as a hash, and a hash of ٠٥٩ is not a hash of 059.
|
| Geist has no one-box-per-digit field; the boxes wear its Input's look, and the label, the helper
| and the error are tied to the input as a Geist field's are.
*/

// Latin, Arabic-Indic and Extended Arabic-Indic digits: input-otp refuses a character its pattern
// does not take, and JavaScript's \d is Latin only.
const DIGITS = '^[0-9٠-٩۰-۹]*$';

type Props = {
    id: string;
    label: ReactNode;
    /** How many digits, from the server's setting. */
    length: number;
    value: string;
    onChange: (code: string) => void;
    helper?: ReactNode;
    error?: string;
    autoFocus?: boolean;
};

export function CodeInput({ id, label, length, value, onChange, helper, error, autoFocus }: Props) {
    const invalid = error !== undefined && error !== '';
    const described = [helper === undefined ? null : `${id}-helper`, invalid ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            {/* Digits read left to right on an Arabic page too. The boxes themselves still sit at the
                field's start, under the label: the outer row keeps the page's direction, and only
                the boxes inside it are turned. */}
            <div className="flex">
                <div dir="ltr">
                    <InputOTP
                        id={id}
                        maxLength={length}
                        pattern={DIGITS}
                        value={value}
                        onChange={(next) => onChange(toLatinDigits(next))}
                        pasteTransformer={(pasted) => toLatinDigits(pasted).replace(/\D/g, '')}
                        autoFocus={autoFocus}
                        aria-invalid={invalid || undefined}
                        aria-describedby={described}
                        data-test="code"
                    >
                        <InputOTPGroup>
                            {Array.from({ length }, (_, index) => (
                                <InputOTPSlot key={index} index={index} aria-invalid={invalid || undefined} className="tw-figure size-10 text-label-20 sm:size-12" />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>
                </div>
            </div>
            {helper === undefined ? null : <FieldDescription id={`${id}-helper`}>{helper}</FieldDescription>}
            {invalid ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
        </Field>
    );
}
