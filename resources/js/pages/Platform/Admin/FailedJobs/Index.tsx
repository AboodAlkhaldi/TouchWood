import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import type { FailedJobRowData, FailedJobsPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E7 - the failed jobs (frontend.md §3.5, platform.md §3).
|
| Work that failed its last try, oldest first, each waiting until somebody retries or deletes it -
| nothing here goes on its own (owner, 2026-09-29). One job at a time: a retry puts it back on its
| queue, a delete removes it unrun, and the delete is asked in the page first, as the media library
| asks. The whole error is on the job's own page; the list shows its first line.
*/

export default function Index({ jobs }: FailedJobsPage) {
    const t = useTranslator();
    const [confirming, setConfirming] = useState<string | null>(null);

    return (
        <AdminLayout title={t('platform::admin_failed_jobs.title')} subtitle={t('platform::admin_failed_jobs.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                {jobs.length === 0 ? (
                    <p className="rounded-lg border border-line bg-surface p-6 text-sm text-ink-muted">
                        {t('platform::admin_failed_jobs.none')}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                        <table className="w-full text-sm">
                            <thead className="border-b border-line text-xs text-ink-muted">
                                <tr>
                                    <th className="px-4 py-2 text-start font-medium">{t('platform::admin_failed_jobs.job')}</th>
                                    <th className="px-4 py-2 text-start font-medium">{t('platform::admin_failed_jobs.failed_at')}</th>
                                    <th className="px-4 py-2 text-start font-medium">{t('platform::admin_failed_jobs.tries')}</th>
                                    <th className="px-4 py-2 text-start font-medium">{t('platform::admin_failed_jobs.error')}</th>
                                    <th className="px-4 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {jobs.map((job) => (
                                    <Row
                                        key={job.id}
                                        job={job}
                                        confirming={confirming === job.id}
                                        onConfirm={() => setConfirming((open) => (open === job.id ? null : job.id))}
                                        onCancel={() => setConfirming(null)}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

type RowProps = {
    job: FailedJobRowData;
    confirming: boolean;
    onConfirm: () => void;
    onCancel: () => void;
};

function Row({ job, confirming, onConfirm, onCancel }: RowProps) {
    const t = useTranslator();

    return (
        <>
            <tr>
                <td className="px-4 py-3">
                    <Link href={`/admin/failed-jobs/${job.id}`} className="text-ink hover:underline" data-test={`open-${job.id}`}>
                        {job.name}
                    </Link>
                </td>
                <td className="tw-figure px-4 py-3 text-xs text-ink-muted" dir="ltr">
                    {job.failedAt.slice(0, 19).replace('T', ' ')}
                </td>
                <td className="tw-figure px-4 py-3 text-xs text-ink-muted">
                    {job.triesAllowed === null ? t('platform::admin_failed_jobs.no_limit') : job.triesAllowed}
                </td>
                <td className="max-w-md px-4 py-3 text-xs text-ink-muted" dir="ltr">
                    <span className="line-clamp-2 break-all">{job.errorLine}</span>
                </td>
                <td className="px-4 py-3">
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            data-test={`retry-${job.id}`}
                            onClick={() => router.post(`/admin/failed-jobs/${job.id}/retry`)}
                        >
                            {t('platform::admin_failed_jobs.retry')}
                        </Button>
                        <Button variant="destructive" size="sm" data-test={`delete-${job.id}`} onClick={onConfirm}>
                            {t('platform::admin_failed_jobs.delete')}
                        </Button>
                    </div>
                </td>
            </tr>

            {confirming ? (
                <tr>
                    <td colSpan={5} className="bg-bad-soft px-4 py-4">
                        <DeleteConfirmation id={job.id} onCancel={onCancel} />
                    </td>
                </tr>
            ) : null}
        </>
    );
}

/** Asked in the page, never with the browser's own box, as the media library asks (owner, 2026-09-24). */
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
