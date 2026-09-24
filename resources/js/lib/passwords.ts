import { useState } from 'react';

/*
| The second password box (frontend.md §2.1).
|
| **It is checked here and nowhere else**, on purpose. Every endpoint that takes a new password
| takes `password` alone - that is what their own tests send, and they have been published that way
| - so the repeat is not a rule of the system. It is a courtesy to the person typing: a typo in a
| box whose contents they cannot see would otherwise set a password they did not mean, silently,
| and be discovered only the next time they tried to sign in (owner, 2026-09-24).
|
| The message appears while they type rather than after they press the button, and the button stays
| out of reach while the two differ - but the check runs on submit too, because Enter in a field
| sends a form without going near the button.
*/

export type RepeatedPassword = {
    /** What is in the second box. */
    value: string;
    setValue: (value: string) => void;
    /** They have typed something in it, and it is not the password. */
    differs: boolean;
    /** After a password is saved, so the next one starts from two empty boxes. */
    clear: () => void;
};

export function useRepeatedPassword(password: string): RepeatedPassword {
    const [value, setValue] = useState('');

    return {
        value,
        setValue,
        // Silent while the box is empty: a complaint about something somebody has not finished
        // typing is noise.
        differs: value !== '' && value !== password,
        clear: () => setValue(''),
    };
}
