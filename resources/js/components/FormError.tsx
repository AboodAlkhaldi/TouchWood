import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/page';

/*
| The business error of a form, said where the person is looking (frontend.md §1.7, §2.1).
|
| A refusal that belongs to no single field - a wrong password, a spent code, a number already in
| use - arrives as `errors.form`. It shows **twice**: as a toast, which fades, and here, at the top
| of the form, which does not. The toast is for the person who has already looked away; this is for
| the person still looking at the form they just sent.
|
| Without it a refusal is a message at the bottom of a tall page that vanishes after six seconds,
| which reads as nothing having happened at all (found by running it, 2026-09-22).
|
| It reads the page's errors rather than the form's: `form` is not a field of any form, so a form's
| own typed errors do not carry it.
*/

export function FormError() {
    const { errors } = usePage<SharedProps>().props;
    const message = errors.form;

    if (message === undefined || message === '') {
        return null;
    }

    return (
        <p
            role="alert"
            className="rounded-md border border-bad/30 bg-bad-soft px-4 py-3 text-sm text-bad"
        >
            {message}
        </p>
    );
}
