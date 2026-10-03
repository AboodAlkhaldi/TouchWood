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
| The button stays out of reach while the two differ, and says why. The message under the box
| appears only once the person leaves it, or tries to send the form - not while they are still
| typing, which is Geist's Input rule ("validate on blur, not on every keystroke"; the batch B
| audit). The check runs on submit too, because Enter in a field sends a form without going near
| the button.
*/

export type RepeatedPassword = {
    /** What is in the second box. */
    value: string;
    setValue: (value: string) => void;
    /** They have typed something in it, and it is not the password. */
    differs: boolean;
    /** Whether the message is said now: it differs, and they have left the box or tried to send. */
    showDiffers: boolean;
    /** For the second box's onBlur. */
    left: () => void;
    /** When the form is sent, so a mismatch caught on submit is said even if the box kept focus. */
    tried: () => void;
    /** After a password is saved, so the next one starts from two empty boxes. */
    clear: () => void;
};

export function useRepeatedPassword(password: string): RepeatedPassword {
    const [value, setValue] = useState('');
    const [settled, setSettled] = useState(false);
    // Silent while the box is empty: a complaint about something somebody has not finished
    // typing is noise.
    const differs = value !== '' && value !== password;

    return {
        value,
        setValue,
        differs,
        showDiffers: differs && settled,
        left: () => setSettled(true),
        tried: () => setSettled(true),
        clear: () => {
            setValue('');
            setSettled(false);
        },
    };
}
