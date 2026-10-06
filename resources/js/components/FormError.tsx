import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Note } from '@/components/Note';
import type { SharedProps } from '@/types/page';

/**
 * The page's refusal, but only one that arrived while this dialog was open.
 *
 * `errors.form` belongs to the page and stays until the next visit; opening a dialog is not a visit.
 * Read plainly, a refusal of Block showed inside the Close Account dialog opened afterwards, before
 * anything had been sent from it (the review of the Geist move, 2026-10-02). Counting the visits
 * that finish after the dialog opened tells this dialog's own answer from an older one.
 */
export function useFreshRefusal(open: boolean): string | undefined {
    const { errors } = usePage<SharedProps>().props;
    const [visits, setVisits] = useState(0);
    const openedAt = useRef(0);

    useEffect(() => router.on('finish', () => setVisits((count) => count + 1)), []);

    useEffect(() => {
        if (open) {
            openedAt.current = visits;
        }
        // Only the moment of opening counts; later visits are what the dialog is waiting for.
    }, [open]);

    const message = errors.form;

    return open && visits > openedAt.current && message !== undefined && message !== '' ? message : undefined;
}

/** The refusal of what a dialog itself sent, said inside it (it stays open on a refusal). */
export function DialogError({ open }: { open: boolean }) {
    const message = useFreshRefusal(open);

    return message === undefined ? null : (
        <Note variant="error" alert data-test="form-error">
            {message}
        </Note>
    );
}

/*
| The business error of a form, said where the person is looking (frontend.md §1.7, §2.1), as
| Geist's error Note (1.10), on shadcn's Alert.
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
        <Note variant="error" alert data-test="form-error">
            {message}
        </Note>
    );
}
