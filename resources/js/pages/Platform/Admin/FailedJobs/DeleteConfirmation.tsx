import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, Modal, ModalCancel } from '@/components/geist';
import { useTranslator } from '@/lib/t';

/*
| E7's delete, asked first, never with the browser's own box (owner, 2026-09-24). Shared by the
| list and a job's own page.
|
| Asked in Geist's destructive Modal (frontend.md 1.10): Geist confirms a delete in a Modal, starts
| focus on Cancel so Enter never deletes by accident, and has the confirm button repeat the title's
| verb and noun. A plain Modal rather than the typed one: a failed job is not a thing worth typing
| a name for. It stays open while the delete is on its way and closes when the answer arrives -
| the toast and the page's own error then say how it went.
*/

type Props = {
    /** The job being asked about; null while nothing is. */
    id: string | null;
    onClose: () => void;
};

export function DeleteConfirmation({ id, onClose }: Props) {
    const t = useTranslator();
    const [deleting, setDeleting] = useState(false);

    function remove() {
        if (id === null) {
            return;
        }

        router.post(
            `/admin/failed-jobs/${id}/delete`,
            {},
            {
                onStart: () => setDeleting(true),
                onFinish: () => {
                    setDeleting(false);
                    onClose();
                },
            },
        );
    }

    return (
        <Modal
            open={id !== null}
            // Never closed from under a delete that is still on its way.
            onOpenChange={(open) => (open || deleting ? undefined : onClose())}
            destructive
            title={t('platform::admin_failed_jobs.delete_title')}
            description={t('platform::admin_failed_jobs.confirm_delete')}
            actions={
                <>
                    <ModalCancel onClick={onClose} disabled={deleting} />
                    <Button
                        type="error"
                        loading={deleting}
                        data-test={id === null ? undefined : `delete-confirm-${id}`}
                        onClick={remove}
                    >
                        {t('platform::admin_failed_jobs.delete_title')}
                    </Button>
                </>
            }
        />
    );
}

/** The tries a job was allowed, in words where the number alone would mislead (platform.md §3). */
export function triesLabel(tries: number | null, t: (key: string) => string): string {
    if (tries === null) {
        return t('platform::admin_failed_jobs.set_by_worker');
    }

    return tries === 0 ? t('platform::admin_failed_jobs.no_limit') : String(tries);
}
