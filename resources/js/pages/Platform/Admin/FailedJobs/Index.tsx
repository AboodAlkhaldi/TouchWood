import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { FailedJobRowData, FailedJobsPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import { DeleteConfirmation, triesLabel } from './DeleteConfirmation';

/*
| E7 - the failed jobs (frontend.md §3.5, platform.md §3), on shadcn's Table, Empty and AlertDialog
| with Geist's rules (§1.11).
|
| Work that failed its last try, oldest first, 50 at a time, each waiting until somebody retries or
| deletes it - nothing here goes on its own (owner, 2026-09-29). One job at a time: a retry puts it
| back on its queue - offered only for a job that failed on the database queue -, a delete removes
| it unrun, and the delete is asked first. The whole error is on the job's own page; the list shows
| its first line.
|
| An empty list is an Empty State, never an empty table; more rows come by keyset, so the list ends
| in Show More, which adds the next page under the rows already shown (owner, 2026-10-04). Two
| actions per row is within Geist's Entity limit, so both stay on the row; Delete Job… opens a
| dialog, so it says so with its "…" and is not red until the dialog's own button.
*/

export default function Index({ jobs, nextFailedAt, nextId }: FailedJobsPage) {
    const t = useTranslator();
    const [confirming, setConfirming] = useState<string | null>(null);
    const list = useLoadMore(jobs, (job) => job.id);

    return (
        <AdminLayout title={t('platform::admin_failed_jobs.title')} subtitle={t('platform::admin_failed_jobs.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                {list.rows.length === 0 ? (
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('platform::admin_failed_jobs.none_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('platform::admin_failed_jobs.none')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="material-base overflow-hidden">
                        <Table>
                            {/* The table's name, for a screen reader (Geist's Table). */}
                            <TableCaption className="sr-only">{t('platform::admin_failed_jobs.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('platform::admin_failed_jobs.job')}</TableHead>
                                    <TableHead>{t('platform::admin_failed_jobs.failed_at')}</TableHead>
                                    <TableHead>{t('platform::admin_failed_jobs.tries')}</TableHead>
                                    <TableHead>{t('platform::admin_failed_jobs.error')}</TableHead>
                                    <TableHead>
                                        <span className="sr-only">{t('platform::admin_failed_jobs.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((job) => (
                                    <Row key={job.id} job={job} onDelete={() => setConfirming(job.id)} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {nextFailedAt !== null && nextId !== null ? (
                    <LoadMoreButton
                        loading={list.loading}
                        onClick={() => list.more('/admin/failed-jobs', { after_at: nextFailedAt, after_id: nextId }, ['jobs', 'nextFailedAt', 'nextId'])}
                    />
                ) : null}
            </div>

            <DeleteConfirmation id={confirming} onClose={() => setConfirming(null)} />
        </AdminLayout>
    );
}

function Row({ job, onDelete }: { job: FailedJobRowData; onDelete: () => void }) {
    const t = useTranslator();
    // Busy while the retry is on its way (Geist's Button: loading, not a second press).
    const [retrying, setRetrying] = useState(false);

    return (
        <TableRow>
            <TableCell>
                <Link href={`/admin/failed-jobs/${job.id}`} className="text-ink underline-offset-4 hover:underline" data-test={`open-${job.id}`}>
                    {job.name}
                </Link>
            </TableCell>
            <TableCell className="text-copy-13 text-ink-muted">
                <Time value={job.failedAt} />
            </TableCell>
            <TableCell className="tw-figure text-copy-13 text-ink-muted">{triesLabel(job.triesAllowed, t)}</TableCell>
            <TableCell className="max-w-md whitespace-normal">
                <bdi dir="ltr" className="line-clamp-2 break-all text-copy-13 text-ink-muted">
                    {job.errorLine}
                </bdi>
            </TableCell>
            <TableCell>
                <div className="flex flex-wrap justify-end gap-2">
                    {job.retryable ? (
                        <ActionButton
                            type="button"
                            variant="outline"
                            size="sm"
                            loading={retrying}
                            data-test={`retry-${job.id}`}
                            onClick={() => router.post(`/admin/failed-jobs/${job.id}/retry`, {}, { onStart: () => setRetrying(true), onFinish: () => setRetrying(false) })}
                        >
                            {t('platform::admin_failed_jobs.retry')}
                        </ActionButton>
                    ) : null}
                    <Button type="button" variant="outline" size="sm" data-test={`delete-${job.id}`} onClick={onDelete}>
                        {t('platform::admin_failed_jobs.delete_open')}
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}
