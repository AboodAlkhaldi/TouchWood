import { type ComponentProps, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';

/*
| A password field with a way to see what you are typing (owner, 2026-09-22).
|
| Typing a long password blind, twice, on a phone keyboard, is how people end up locked out of an
| account they just created. The eye is off by default - the password is hidden until the person
| asks - and it never travels anywhere: showing it is only this browser, this field, this moment.
|
| The button is not in the tab order. Someone moving through the form with the keyboard wants to go
| from the password to the next field, not via a button they did not ask for; it is still reachable
| by pointer, and screen readers announce the field's own state through aria-pressed.
*/

type Props = Omit<ComponentProps<typeof Input>, 'type'>;

export function PasswordInput({ className = '', ...props }: Props) {
    const t = useTranslator();
    const [shown, setShown] = useState(false);

    return (
        <div className="relative">
            <Input
                {...props}
                type={shown ? 'text' : 'password'}
                // Room for the button at the end of the field, whichever way the page runs.
                className={`pe-10 ${className}`}
            />

            <button
                type="button"
                tabIndex={-1}
                aria-pressed={shown}
                aria-label={t(shown ? 'access::auth.hide_password' : 'access::auth.show_password')}
                title={t(shown ? 'access::auth.hide_password' : 'access::auth.show_password')}
                onClick={() => setShown((was) => !was)}
                className="absolute inset-y-0 end-0 grid w-10 place-items-center text-ink-muted transition-colors hover:text-brand"
            >
                {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </button>
        </div>
    );
}
