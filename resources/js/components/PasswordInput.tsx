import { type ComponentProps, type ReactNode, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { checked, describedBy, Messages } from '@/components/Fields';
import { Field, FieldLabel } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { useTranslator } from '@/lib/t';
import type { BoxCheck } from '@/lib/use-checks';

/*
| A password field with a way to see what you are typing (owner, 2026-09-22), on shadcn's
| input-group as its own form examples put a password (frontend.md §1.11), with a label, a helper
| and an error like any other field.
|
| Typing a long password blind, twice, on a phone keyboard, is how people end up locked out of an
| account they just created. The eye is off by default - the password is hidden until the person
| asks - and it never travels anywhere: showing it is only this browser, this field, this moment.
|
| The eye is a real button, in the keyboard's reach, named once ("Show password") and saying its
| state with aria-pressed - a toggle's name stays the same while its state changes, so the state is
| never said twice (the review of batch C) - not a tooltip repeating its name (Geist's Tooltip
| rules), and no longer taken out of the tab order, which left a keyboard user no way to see the
| password (the batch B audit).
*/

type Props = Omit<ComponentProps<typeof InputGroupInput>, 'type' | 'id'> & {
    id: string;
    label: ReactNode;
    helper?: ReactNode;
    error?: string;
    /** Beside the label, at its end: login-02 puts "Forgot your password?" there. */
    labelEnd?: ReactNode;
    className?: string;
    /** Its check as it is typed (frontend.md §1.7), as the shared fields take one (Fields.tsx). */
    check?: BoxCheck;
};

export function PasswordInput({ id, label, helper, error, labelEnd, className, check, ...input }: Props) {
    const t = useTranslator();
    const [shown, setShown] = useState(false);
    const message = check === undefined ? error : check.message;
    const invalid = message !== undefined && message !== '';

    return (
        <Field className={className}>
            {labelEnd === undefined ? (
                <FieldLabel htmlFor={id}>{label}</FieldLabel>
            ) : (
                <div className="flex items-center">
                    <FieldLabel htmlFor={id}>{label}</FieldLabel>
                    <span className="ms-auto">{labelEnd}</span>
                </div>
            )}
            <InputGroup>
                <InputGroupInput
                    {...input}
                    {...checked(check, input)}
                    onChange={(event) => {
                        input.onChange?.(event);
                        check?.onType();
                    }}
                    id={id}
                    type={shown ? 'text' : 'password'}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy(id, helper, message)}
                />
                <InputGroupAddon align="inline-end">
                    <InputGroupButton size="icon-xs" aria-pressed={shown} aria-label={t('ui.show_password')} onClick={() => setShown((was) => !was)}>
                        {shown ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
                    </InputGroupButton>
                </InputGroupAddon>
            </InputGroup>
            <Messages id={id} helper={helper} error={message} check={check} />
        </Field>
    );
}
