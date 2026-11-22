import { type RefObject, useState } from 'react';
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
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';

/*
| E7's delete, asked first, never with the browser's own box (owner, 2026-09-24). Shared by the
| list and a job's own page.
|
| shadcn's AlertDialog (frontend.md §1.11): it says it is an alert dialog, starts on Cancel so Enter
| never deletes by accident, and the confirm button repeats the title's verb and noun (Geist's
| Modal). A plain confirmation rather than a typed one: a failed job is not a thing worth typing a
| name for. It stays open while the delete is on its way; a refusal is said inside it.
|
| Opened by the page, not by a Trigger of its own, so focus is handed back to whatever opened it.
*/

type Props = {
    /** The job being asked about; null while nothing is. */
    id: string | null;
    onClose: () => void;
    /** Where focus goes back when what opened it is gone - a ⋯ menu's item closes with its menu. */
    returnTo?: RefObject<HTMLElement | null>;
};

export function DeleteConfirmation({ id, onClose, returnTo }: Props) {
    const t = useTranslator();
    const [deleting, setDeleting] = useState(false);
    const open = id !== null;
    const returnFocus = useReturnFocus(open, returnTo);

    function remove() {
        if (id === null) {
            return;
        }

        router.post(
            `/admin/failed-jobs/${id}/delete`,
            {},
            {
                onStart: () => setDeleting(true),
                // Closed once it is gone; a refusal keeps it open, with the reason inside it.
                onSuccess: () => onClose(),
                onFinish: () => setDeleting(false),
            },
        );
    }

    return (
        <AlertDialog open={open} onOpenChange={(next) => (next || deleting ? undefined : onClose())}>
            <AlertDialogContent onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 data-[size=default]:sm:max-w-md">
                <div className="grid gap-4 p-6">
                    <AlertDialogHeader>
                        <AlertDialogTitle className="text-heading-20 text-ink">{t('platform::admin_failed_jobs.delete_title')}</AlertDialogTitle>
                        <AlertDialogDescription className="text-copy-14 text-ink-muted">{t('platform::admin_failed_jobs.confirm_delete')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <DialogError open={open} />
                </div>
                <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                    <AlertDialogCancel disabled={deleting} data-test="modal-cancel">
                        {t('ui.cancel')}
                    </AlertDialogCancel>
                    <ActionButton variant="destructive" loading={deleting} onClick={remove} data-test={id === null ? undefined : `delete-confirm-${id}`}>
                        {t('platform::admin_failed_jobs.delete_title')}
                    </ActionButton>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** The tries a job was allowed, in words where the number alone would mislead (platform.md §3). */
export function triesLabel(tries: number | null, t: (key: string) => string): string {
    if (tries === null) {
        return t('platform::admin_failed_jobs.set_by_worker');
    }

    return tries === 0 ? t('platform::admin_failed_jobs.no_limit') : String(tries);
}
