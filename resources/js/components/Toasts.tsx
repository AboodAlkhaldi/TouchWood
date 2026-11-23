import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { Toaster } from '@/components/ui/sonner';
import type { SharedProps } from '@/types/page';

/*
| What the last request had to say (frontend.md §2.1), in shadcn's Sonner - edit 2 of §1.11 - with
| Geist's toast rules on top: at the bottom end of the screen, the tone by meaning.
|
| A success is the flash `status` ("Invitation sent"). A business error shows twice - here, and as
| a red message beside what it concerns, which the form itself renders: the toast fades, the message
| beside the field stays until the person changes the form.
|
| Said once per finished visit, never remembered by its words: a person who gets the password wrong
| twice must hear it twice, or the button reads as broken (found by running it, 2026-09-22). Sonner
| draws in the browser, so the first page's message appears as the page comes to life.
*/

/*
| The server's answer last said, by identity: every answer is a new props object, a repeat of the same one is not. Every
| layout draws its own Toasts, so an action that lands on another page builds a new one, which spoke as it was built
| and again when the visit finished - "Signed out" twice (the owner's report, 2026-11-24; ToastsOnceTest). Kept outside
| the component, so the new one knows what the old one said.
*/
let said: object | null = null;

export function Toasts() {
    const { props } = usePage<SharedProps>();
    const { flash, errors, locale } = props;
    const [visit, setVisit] = useState(0);

    useEffect(() => router.on('finish', () => setVisit((count) => count + 1)), []);

    useEffect(() => {
        if (said === props) {
            return;
        }

        said = props;

        if (flash.status) {
            toast.success(flash.status);
        }

        // What the success left to see to - orders a count left short: amber, after it.
        if (flash.warning) {
            toast.warning(flash.warning);
        }

        // `form` is the business error of §1.7: the one that belongs to no single field.
        if (errors.form) {
            toast.error(errors.form);
        }
        // Keyed by the visit: each answer from the server is said, whether or not it says the same
        // thing as the last one.
    }, [visit]);

    // The bottom end of the screen: right on an English page, left on an Arabic one. **Coloured by kind**
    // (flags-2026-11.md S1.6, F5: the shop and the panel alike): our good, warn, bad and info pairs, both modes; important,
    // so they win over sonner's own neutral surface.
    return (
        <Toaster
            position={locale === 'ar' ? 'bottom-left' : 'bottom-right'}
            dir={locale === 'ar' ? 'rtl' : 'ltr'}
            toastOptions={{
                classNames: {
                    success: 'bg-good-soft! text-good! border-good/30!',
                    warning: 'bg-warn-soft! text-warn! border-warn/30!',
                    error: 'bg-bad-soft! text-bad! border-bad/30!',
                    info: 'bg-info-soft! text-info! border-info/30!',
                },
            }}
        />
    );
}
