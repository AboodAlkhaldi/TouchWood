import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
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
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';

/*
| The company pages' two confirmations (b2b.md §4.5, amendment 35), loaded on their first press and
| never with a page: no page carries them on first paint (frontend.md §5, JavaScriptBudgetTest; owner,
| 2026-11-11: "continue trimming"). Once loaded, a dialog stays mounted, so it closes with its
| animation and gives focus back as before. Nothing here renders on the server: until it is asked
| for, it is not there (lesson 180). Named exports only - a default one would count as a page.
*/

/**
 * Discarding the draft, asked in the page, never with the browser's own box (owner, 2026-09-24) -
 * in shadcn's AlertDialog, which confirms what destroys: the draft goes, and any file only it holds.
 * Being destructive, it opens on Cancel, so Enter never discards by accident. Not a typed
 * confirmation: Geist keeps that friction for what is hard to undo, and a draft is started again
 * with one press. Used by the form and by a suspended company's frozen draft.
 */
export function DiscardModal({ open, onOpenChange, busy = false }: { open: boolean; onOpenChange: (open: boolean) => void; busy?: boolean }) {
    const t = useTranslator();
    const link = useLink();
    const [discarding, setDiscarding] = useState(false);
    const returnFocus = useReturnFocus(open);

    return (
        <AlertDialog
            open={open}
            // Not closed under a discard still out: its answer decides what the page shows next.
            onOpenChange={(next) => (discarding ? undefined : onOpenChange(next))}
        >
            <AlertDialogContent onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 text-start data-[size=default]:sm:max-w-md">
                <div className="grid gap-4 p-6">
                    <AlertDialogHeader>
                        <AlertDialogTitle className="text-heading-20 text-ink">{t('b2b::company.discard_title')}</AlertDialogTitle>
                        <AlertDialogDescription className="text-copy-14 text-ink-muted" data-test="discard-confirmation">
                            {t('b2b::company.discard_confirm')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {/* A refusal keeps the dialog open; it is said inside it, not only in a toast
                        hidden under the backdrop (the review of the move). */}
                    <DialogError open={open} />
                </div>
                <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                    <AlertDialogCancel disabled={discarding} data-test="modal-cancel">
                        {t('ui.cancel')}
                    </AlertDialogCancel>
                    <ActionButton
                        variant="destructive"
                        data-test="discard-confirm"
                        loading={discarding}
                        // Not while a save is out: its page request would cancel the saves (17(i)).
                        disabledReason={busy ? t('b2b::company.saving_wait') : undefined}
                        onClick={() => {
                            setDiscarding(true);
                            router.post(link('storefront.company.discard'), {}, { onFinish: () => setDiscarding(false) });
                        }}
                    >
                        {t('b2b::company.discard_yes')}
                    </ActionButton>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * Removing a paper, confirmed first (shadcn's AlertDialog). Where focus goes back to is the row's
 * own choice (`onCloseAutoFocus`): Choose File once removed, the ⋯ button that opened it if not.
 */
export function RemoveFileDialog({
    open,
    onOpenChange,
    onCloseAutoFocus,
    title,
    test,
    onRemove,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCloseAutoFocus: (event: Event) => void;
    title: string;
    test?: string;
    onRemove: () => void;
}) {
    const t = useTranslator();

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent onCloseAutoFocus={onCloseAutoFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 text-start data-[size=default]:sm:max-w-md">
                <div className="grid gap-4 p-6">
                    <AlertDialogHeader>
                        <AlertDialogTitle className="text-heading-20 text-ink">{t('b2b::company.remove_title')}</AlertDialogTitle>
                        <AlertDialogDescription className="text-copy-14 text-ink-muted">{t('b2b::company.remove_confirm', { name: title })}</AlertDialogDescription>
                    </AlertDialogHeader>
                </div>
                <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                    <AlertDialogCancel data-test="modal-cancel">{t('ui.cancel')}</AlertDialogCancel>
                    <ActionButton variant="destructive" data-test={test === undefined ? undefined : `${test}-remove-confirm`} onClick={onRemove}>
                        {t('b2b::company.remove')}
                    </ActionButton>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
