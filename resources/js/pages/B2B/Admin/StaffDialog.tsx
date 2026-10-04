import type { ReactNode, RefObject } from 'react';
import { DialogError } from '@/components/FormError';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';

/*
| The frame of the dialogs on B2B's staff screens - a decision on a company, a change to a type list
| (b2b.md §4.6) - all but Suspend, which asks for the company's name typed (DestructiveActionDialog,
| amendment 23(c)): shadcn's Dialog, or its AlertDialog for a destructive one (focus starting on Cancel),
| with Geist's Modal rules - a title stating what happens, the primary button repeating it, a refusal
| said inside the dialog, never behind its backdrop, and the dialog kept open to retry.
*/

export function StaffDialog({
    open,
    onOpenChange,
    destructive = false,
    wide = false,
    title,
    description,
    busy,
    confirm,
    children,
    returnFocusTo,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    destructive?: boolean;
    wide?: boolean;
    title: string;
    description: string;
    busy: boolean;
    confirm: ReactNode;
    children: ReactNode;
    /** Where focus goes back when the opener is gone: the ⋯ button of the menu that opened it. */
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const returnFocus = useReturnFocus(open, returnFocusTo);
    // Never closed from under a decision still on its way.
    const change = (next: boolean) => (busy && !next ? undefined : onOpenChange(next));
    // Written out whole: Tailwind finds a class only as it is spelled in the source.
    const dialogWidth = wide ? 'sm:max-w-2xl' : 'sm:max-w-md';
    const alertWidth = wide ? 'data-[size=default]:sm:max-w-2xl' : 'data-[size=default]:sm:max-w-md';

    const body = (
        <div className="grid max-h-[70vh] gap-4 overflow-y-auto p-6">
            {destructive ? (
                <AlertDialogHeader>
                    <AlertDialogTitle className="text-heading-20 text-ink">{title}</AlertDialogTitle>
                    <AlertDialogDescription className="text-copy-14 text-ink-muted">{description}</AlertDialogDescription>
                </AlertDialogHeader>
            ) : (
                <DialogHeader>
                    <DialogTitle className="text-heading-20 text-ink">{title}</DialogTitle>
                    <DialogDescription className="text-copy-14 text-ink-muted">{description}</DialogDescription>
                </DialogHeader>
            )}
            {/* Inside the dialog: a refusal that names no field would sit behind its backdrop. */}
            <DialogError open={open} />
            {children}
        </div>
    );

    return destructive ? (
        <AlertDialog open={open} onOpenChange={change}>
            <AlertDialogContent onCloseAutoFocus={returnFocus} className={`material-modal gap-0 overflow-hidden border-0 p-0 text-start ${alertWidth}`}>
                {body}
                <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                    <AlertDialogCancel disabled={busy} data-test="modal-cancel">
                        {t('ui.cancel')}
                    </AlertDialogCancel>
                    {confirm}
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    ) : (
        <Dialog open={open} onOpenChange={change}>
            <DialogContent showCloseButton={false} onCloseAutoFocus={returnFocus} className={`material-modal gap-0 overflow-hidden border-0 p-0 text-start ${dialogWidth}`}>
                {body}
                <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                    <Button type="button" variant="outline" disabled={busy} onClick={() => onOpenChange(false)} data-test="modal-cancel">
                        {t('ui.cancel')}
                    </Button>
                    {confirm}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
