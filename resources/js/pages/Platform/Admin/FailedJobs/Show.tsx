import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import type { FailedJobPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import { DeleteConfirmation } from './Index';

/*
| E7 - one failed job, with its whole error (frontend.md §3.5).
|
| The error is shown as it was written, stack trace and all: it is what somebody needs to know why
| the job failed. It may quote the values the job was writing, which is why only admins given
| platform.jobs.manage reach this page. Retrying or deleting from here lands back on the list.
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
                    <Button
                        variant="outline"
                        data-test="retry"
                        onClick={() => router.post(`/admin/failed-jobs/${job.id}/retry`)}
                    >
                        {t('platform::admin_failed_jobs.retry')}
                    </Button>
                    <Button variant="destructive" data-test="delete" onClick={() => setConfirming((open) => !open)}>
                        {t('platform::admin_failed_jobs.delete')}
                    </Button>
                </div>
            }
        >
            <div className="grid gap-4">
                <FormError />

                {confirming ? (
                    <div className="rounded-lg border border-bad/30 bg-bad-soft p-4">
                        <DeleteConfirmation id={job.id} onCancel={() => setConfirming(false)} />
                    </div>
                ) : null}

                <dl className="grid gap-3 rounded-lg border border-line bg-surface p-4 text-sm sm:grid-cols-3">
                    <div className="grid gap-1">
                        <dt className="text-xs text-ink-muted">{t('platform::admin_failed_jobs.failed_at')}</dt>
                        <dd className="tw-figure text-ink" dir="ltr">
                            {job.failedAt.slice(0, 19).replace('T', ' ')}
                        </dd>
                    </div>
                    <div className="grid gap-1">
                        <dt className="text-xs text-ink-muted">{t('platform::admin_failed_jobs.tries')}</dt>
                        <dd className="tw-figure text-ink">
                            {job.triesAllowed === null ? t('platform::admin_failed_jobs.no_limit') : job.triesAllowed}
                        </dd>
                    </div>
                    <div className="grid gap-1">
                        <dt className="text-xs text-ink-muted">{t('platform::admin_failed_jobs.queue')}</dt>
                        <dd className="text-ink" dir="ltr">
                            {job.queue}
                        </dd>
                    </div>
                </dl>

                <section className="grid gap-2">
                    <h2 className="text-sm font-semibold text-ink">{t('platform::admin_failed_jobs.error')}</h2>
                    <pre
                        dir="ltr"
                        data-test="error"
                        className="max-h-[32rem] overflow-auto rounded-lg border border-line bg-surface-sunken p-4 text-xs whitespace-pre-wrap break-all text-ink"
                    >
                        {error}
                    </pre>
                </section>
            </div>
        </AdminLayout>
    );
}
