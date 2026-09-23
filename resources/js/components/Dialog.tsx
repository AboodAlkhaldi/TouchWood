import type { ReactNode } from 'react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { X } from 'lucide-react';
import { useTranslator } from '@/lib/t';

/*
| A modal that asks for something before it acts (frontend.md §2.1, §6).
|
| Radix does the parts that are easy to get wrong and impossible to notice until somebody needs
| them: the focus goes into the dialog and cannot leave it, Escape closes it, and focus returns to
| whatever opened it. §6 asks for exactly that, so it is not re-implemented here.
|
| Centred with auto margins rather than a translate. A dialog placed with -translate-x-1/2 sits off
| to one side on an Arabic page, because the offset is written in physical pixels while `start` has
| already mirrored - the same class of bug that put the sidebar a width off the right edge in step 1.
| Auto margins have no direction at all.
|
| Nothing here reads `window`: the portal renders in the browser only, and a dialog starts closed,
| so the server renders the page without it and nothing differs at hydration.
*/

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /** A sentence under the title saying what the dialog is about to do. */
    description?: string;
    children: ReactNode;
};

export function Dialog({ open, onOpenChange, title, description, children }: Props) {
    const t = useTranslator();

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-ink/50" />

                <DialogPrimitive.Content className="fixed inset-0 z-50 m-auto h-fit max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-lg border border-line bg-surface p-6 shadow-pop">
                    <div className="mb-4 flex items-start justify-between gap-4">
                        <div className="grid gap-1.5">
                            <DialogPrimitive.Title className="text-base font-semibold text-ink">
                                {title}
                            </DialogPrimitive.Title>

                            {description === undefined ? null : (
                                <DialogPrimitive.Description className="text-sm text-ink-muted">
                                    {description}
                                </DialogPrimitive.Description>
                            )}
                        </div>

                        {/* The layout's own file, not any one screen's: every admin page ships
                            `admin`, so a shared component may rely on it and no screen has to
                            remember to carry a word it never mentions. */}
                        <DialogPrimitive.Close
                            aria-label={t('admin.close')}
                            className="rounded-md p-1 text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                        >
                            <X className="size-4" />
                        </DialogPrimitive.Close>
                    </div>

                    {children}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
