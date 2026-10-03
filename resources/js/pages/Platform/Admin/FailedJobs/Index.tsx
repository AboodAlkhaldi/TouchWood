import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import {
    Button,
    EmptyState,
    LoadMoreButton,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { FailedJobRowData, FailedJobsPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import { DeleteConfirmation, triesLabel } from './DeleteConfirmation';
import { Time } from '@/components/geist/Time';

/*
| E7 - the failed jobs (frontend.md §3.5, platform.md §3), in Geist's parts (1.10).
|
| Work that failed its last try, oldest first, 50 at a time, each waiting until somebody retries or
| deletes it - nothing here goes on its own (owner, 2026-09-29). One job at a time: a retry puts it
| back on its queue - offered only for a job that failed on the database queue -, a delete removes
| it unrun, and the delete is asked first, in Geist's Modal. The whole error is on the job's own
| page; the list shows its first line.
|
| An empty list is Geist's Empty State, never an empty table; more rows come by keyset, so the list
| ends in Load More rather than numbered pages.
*/

export default function Index({ jobs, nextFailedAt, nextId }: FailedJobsPage) {
    const t = useTranslator();
    const [confirming, setConfirming] = useState<string | null>(null);

    return (
        <AdminLayout title={t('platform::admin_failed_jobs.title')} subtitle={t('platform::admin_failed_jobs.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                {jobs.length === 0 ? (
                    <EmptyState
                        title={t('platform::admin_failed_jobs.none_title')}
                        description={t('platform::admin_failed_jobs.none')}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('platform::admin_failed_jobs.job')}</TableHead>
                                <TableHead>{t('platform::admin_failed_jobs.failed_at')}</TableHead>
                                <TableHead>{t('platform::admin_failed_jobs.tries')}</TableHead>
                                <TableHead>{t('platform::admin_failed_jobs.error')}</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {jobs.map((job) => (
                                <Row key={job.id} job={job} onDelete={() => setConfirming(job.id)} />
                            ))}
                        </TableBody>
                    </Table>
                )}

                {nextFailedAt !== null && nextId !== null ? (
                    <LoadMoreButton
                        data-test="more"
                        onClick={() => router.get('/admin/failed-jobs', { after_at: nextFailedAt, after_id: nextId })}
                    />
                ) : null}
            </div>

            <DeleteConfirmation id={confirming} onClose={() => setConfirming(null)} />
        </AdminLayout>
    );
}

function Row({ job, onDelete }: { job: FailedJobRowData; onDelete: () => void }) {
    const t = useTranslator();

    return (
        <TableRow>
            <TableCell>
                <Link href={`/admin/failed-jobs/${job.id}`} className="text-ink hover:underline" data-test={`open-${job.id}`}>
                    {job.name}
                </Link>
            </TableCell>
            {/* The cell keeps Geist's colour; the quieter type is on what it holds. */}
            <TableCell>
                <span className="text-copy-13 text-ink-muted">
                    <Time value={job.failedAt} />
                </span>
            </TableCell>
            <TableCell>
                <span className="tw-figure text-copy-13 text-ink-muted">{triesLabel(job.triesAllowed, t)}</span>
            </TableCell>
            <TableCell className="max-w-md" dir="ltr">
                <span className="line-clamp-2 break-all text-copy-13 text-ink-muted">{job.errorLine}</span>
            </TableCell>
            <TableCell>
                <div className="flex flex-wrap justify-end gap-2">
                    {job.retryable ? (
                        <Button
                            type="secondary"
                            size="small"
                            data-test={`retry-${job.id}`}
                            onClick={() => router.post(`/admin/failed-jobs/${job.id}/retry`)}
                        >
                            {t('platform::admin_failed_jobs.retry')}
                        </Button>
                    ) : null}
                    <Button type="error" size="small" data-test={`delete-${job.id}`} onClick={onDelete}>
                        {t('platform::admin_failed_jobs.delete')}
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}
