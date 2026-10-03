import { type ComponentProps, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { Input } from '@/components/geist';
import { useTranslator } from '@/lib/t';

/*
| A password field with a way to see what you are typing (owner, 2026-09-22), on Geist's Input
| (frontend.md 1.10) - so it takes the same label, helper and error as any other field.
|
| Typing a long password blind, twice, on a phone keyboard, is how people end up locked out of an
| account they just created. The eye is off by default - the password is hidden until the person
| asks - and it never travels anywhere: showing it is only this browser, this field, this moment.
|
| The eye is an icon-only button, so it names its action ("Show password") for a screen reader. It
| is not in the tab order: someone moving through the form wants the next field, not a button they
| did not ask for; aria-pressed says the field's state.
*/

type Props = Omit<ComponentProps<typeof Input>, 'type' | 'suffix'>;

export function PasswordInput(props: Props) {
    const t = useTranslator();
    const [shown, setShown] = useState(false);
    const label = t(shown ? 'ui.hide_password' : 'ui.show_password');

    return (
        <Input
            {...props}
            type={shown ? 'text' : 'password'}
            suffix={
                <button
                    type="button"
                    tabIndex={-1}
                    aria-pressed={shown}
                    aria-label={label}
                    title={label}
                    onClick={() => setShown((was) => !was)}
                    className="-me-1 grid size-7 place-items-center rounded-[var(--tw-radius-sm)] text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                >
                    {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
            }
        />
    );
}
