import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { Toaster } from '@/components/ui/sonner';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| What the last request had to say (frontend.md §2.1), in shadcn's Sonner - edit 2 of §1.11 - with
| Geist's toast rules on top: at the bottom end of the screen, the tone by meaning.
|
| A success is the flash `status` ("Invitation sent"). A business error shows twice - here, and as
| a red message beside what it concerns, which the form itself renders: the toast fades, the message
| beside the field stays until the person changes the form. A store the panel had to leave is said
| as a warning, not an error (access.md amendment 58; the audit of the foundation).
|
| Said once per finished visit, never remembered by its words: a person who gets the password wrong
| twice must hear it twice, or the button reads as broken (found by running it, 2026-09-22). Sonner
| draws in the browser, so the first page's message appears as the page comes to life.
*/

export function Toasts() {
    const { flash, errors, store, locale } = usePage<SharedProps>().props;
    const t = useTranslator();
    const [visit, setVisit] = useState(0);

    useEffect(() => router.on('finish', () => setVisit((count) => count + 1)), []);

    // "You no longer have access to that store; showing KSA." The server decided it; this is where
    // the person is told (frontend.md §2.2). A store switched off is said so (access.md amendment 58).
    const fellBack =
        store?.fellBack === true && store.current !== null
            ? t(store.fellBackFromOff ? 'admin.store.fell_back_off' : 'admin.store.fell_back', { store: store.current.name })
            : null;

    useEffect(() => {
        if (flash.status) {
            toast.success(flash.status);
        }

        if (fellBack !== null) {
            toast.warning(fellBack);
        }

        // `form` is the business error of §1.7: the one that belongs to no single field.
        if (errors.form) {
            toast.error(errors.form);
        }
        // Keyed by the visit: each answer from the server is said, whether or not it says the same
        // thing as the last one.
    }, [visit]);

    // The bottom end of the screen: right on an English page, left on an Arabic one.
    return <Toaster position={locale === 'ar' ? 'bottom-left' : 'bottom-right'} dir={locale === 'ar' ? 'rtl' : 'ltr'} />;
}
