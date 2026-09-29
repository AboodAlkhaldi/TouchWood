import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';

/*
| E7's delete, asked in the page first, never with the browser's own box, as the media library asks
| (owner, 2026-09-24). Shared by the list and a job's own page.
*/

export function DeleteConfirmation({ id, onCancel }: { id: string; onCancel: () => void }) {
    const t = useTranslator();

    return (
        <div className="grid gap-3">
            <p className="text-sm text-ink">{t('platform::admin_failed_jobs.confirm_delete')}</p>
            <div className="flex flex-wrap gap-2">
                <Button
                    variant="destructive"
                    size="sm"
                    data-test={`delete-confirm-${id}`}
                    onClick={() => router.post(`/admin/failed-jobs/${id}/delete`)}
                >
                    {t('platform::admin_failed_jobs.delete')}
                </Button>
                <Button variant="outline" size="sm" onClick={onCancel}>
                    {t('platform::admin_failed_jobs.cancel')}
                </Button>
            </div>
        </div>
    );
}

/** The tries a job was allowed, in words where the number alone would mislead (platform.md §3). */
export function triesLabel(tries: number | null, t: (key: string) => string): string {
    if (tries === null) {
        return t('platform::admin_failed_jobs.set_by_worker');
    }

    return tries === 0 ? t('platform::admin_failed_jobs.no_limit') : String(tries);
}
