import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Time } from '@/components/Time';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { Description } from '@/components/geist-only/Description';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useTranslator } from '@/lib/t';
import type { FailedJobPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import { DeleteConfirmation, triesLabel } from './DeleteConfirmation';

/*
| E7 - one failed job, with its whole error (frontend.md §3.5), on shadcn's parts with Geist's rules
| (§1.11).
|
| The error is shown as it was written, stack trace and all: it is what somebody needs to know why
| the job failed. It may quote the values the job was writing, which is why only admins given
| platform.jobs.manage reach this page. A plain block is right for it (Geist's Code Block is for
| highlighted source): shadcn's ScrollArea bounds it, and Geist's Copy Button copies it whole, for a
| support thread.
|
| The page header keeps one main button, Retry Job, and puts Delete Job… in a ⋯ menu (frontend.md
| §1.11, Geist's Button). Retrying or deleting from here lands back on the list; the delete is
| asked first, in the dialog the list uses. The job's facts are Geist's Description.
*/

export default function Show({ job, error }: FailedJobPage) {
    const t = useTranslator();
    const [confirming, setConfirming] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const more = useRef<HTMLButtonElement>(null);

    return (
        <AdminLayout
            title={job.name}
            // The trail says "Failed jobs", from the menu entry this page sits under.
            action={
                <div className="flex items-center gap-2">
                    {/* Only a job that failed on the database queue is put back (platform.md §3). */}
                    {job.retryable ? (
                        <ActionButton
                            loading={retrying}
                            data-test="retry"
                            onClick={() => router.post(`/admin/failed-jobs/${job.id}/retry`, {}, { onStart: () => setRetrying(true), onFinish: () => setRetrying(false) })}
                        >
                            {t('platform::admin_failed_jobs.retry')}
                        </ActionButton>
                    ) : null}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button ref={more} type="button" variant="outline" size="icon" aria-label={t('ui.more_actions')} title={t('ui.more_actions')} data-test="more-actions">
                                <MoreHorizontal aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-48">
                            <DropdownMenuItem variant="destructive" data-test="delete" onSelect={() => setConfirming(true)}>
                                {t('platform::admin_failed_jobs.delete_open')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            }
        >
            <div className="grid gap-4">
                <FormError />

                <Card className="material-base border-0 py-0">
                    <CardContent className="p-5">
                        <Description
                            columns={3}
                            items={[
                                { title: t('platform::admin_failed_jobs.failed_at'), content: <Time value={job.failedAt} mode="absolute" /> },
                                { title: t('platform::admin_failed_jobs.tries'), content: <span className="tw-figure">{triesLabel(job.triesAllowed, t)}</span> },
                                { title: t('platform::admin_failed_jobs.queue'), content: <bdi dir="ltr">{job.queue}</bdi> },
                            ]}
                        />
                    </CardContent>
                </Card>

                <section className="grid gap-2" aria-labelledby="error-title">
                    <div className="flex items-center justify-between gap-2">
                        <h2 id="error-title" className="text-heading-14 text-ink">
                            {t('platform::admin_failed_jobs.error')}
                        </h2>
                        <CopyButton text={error} label={t('platform::admin_failed_jobs.copy_error')} />
                    </div>
                    {/* The height is capped on the viewport: Radix scrolls its viewport, and a cap on the root alone
                        would let the viewport grow with the text and never scroll. */}
                    <ScrollArea className="rounded-[var(--tw-radius)] border border-line bg-surface-sunken [&>[data-slot=scroll-area-viewport]]:max-h-[32rem]">
                        <pre dir="ltr" data-test="error" className="p-4 text-copy-13-mono whitespace-pre-wrap break-all text-ink">
                            {error}
                        </pre>
                    </ScrollArea>
                </section>
            </div>

            <DeleteConfirmation id={confirming ? job.id : null} onClose={() => setConfirming(false)} returnTo={more} />
        </AdminLayout>
    );
}
