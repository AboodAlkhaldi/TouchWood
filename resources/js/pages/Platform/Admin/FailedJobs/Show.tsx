import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button, Description } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { FailedJobPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import { DeleteConfirmation, triesLabel } from './DeleteConfirmation';
import { Time } from '@/components/geist/Time';

/*
| E7 - one failed job, with its whole error (frontend.md §3.5), in Geist's parts (1.10).
|
| The error is shown as it was written, stack trace and all: it is what somebody needs to know why
| the job failed. It may quote the values the job was writing, which is why only admins given
| platform.jobs.manage reach this page. Retrying or deleting from here lands back on the list; the
| delete is asked first, in the same Modal the list uses.
|
| The job's facts are Geist's Description - a key and its value, read as a definition list.
*/

export default function Show({ job, error }: FailedJobPage) {
    const t = useTranslator();
    const [confirming, setConfirming] = useState(false);

    return (
        <AdminLayout
            title={job.name}
            // The trail says "Failed jobs", from the menu entry this page sits under.
            action={
                <div className="flex flex-wrap gap-2">
                    {/* Only a job that failed on the database queue is put back (platform.md §3). */}
                    {job.retryable ? (
                        <Button
                            type="secondary"
                            data-test="retry"
                            onClick={() => router.post(`/admin/failed-jobs/${job.id}/retry`)}
                        >
                            {t('platform::admin_failed_jobs.retry')}
                        </Button>
                    ) : null}
                    <Button type="error" data-test="delete" onClick={() => setConfirming(true)}>
                        {t('platform::admin_failed_jobs.delete')}
                    </Button>
                </div>
            }
        >
            <div className="grid gap-4">
                <FormError />

                <div className="material-base p-4">
                    <Description
                        columns={3}
                        items={[
                            {
                                title: t('platform::admin_failed_jobs.failed_at'),
                                content: (
                                    <Time value={job.failedAt} mode="absolute" />
                                ),
                            },
                            {
                                title: t('platform::admin_failed_jobs.tries'),
                                content: <span className="tw-figure">{triesLabel(job.triesAllowed, t)}</span>,
                            },
                            {
                                title: t('platform::admin_failed_jobs.queue'),
                                content: <span dir="ltr">{job.queue}</span>,
                            },
                        ]}
                    />
                </div>

                <section className="grid gap-2">
                    <h2 className="text-heading-14 text-ink">{t('platform::admin_failed_jobs.error')}</h2>
                    <pre
                        dir="ltr"
                        data-test="error"
                        className="max-h-[32rem] overflow-auto rounded-[var(--tw-radius)] border border-line bg-surface-sunken p-4 text-copy-13-mono whitespace-pre-wrap break-all text-ink"
                    >
                        {error}
                    </pre>
                </section>
            </div>

            <DeleteConfirmation id={confirming ? job.id : null} onClose={() => setConfirming(false)} />
        </AdminLayout>
    );
}
