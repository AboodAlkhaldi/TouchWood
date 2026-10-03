import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import {
    Button,
    EmptyState,
    Input,
    LoadMoreButton,
    Modal,
    ModalCancel,
    Select,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { MediaFileRow, MediaPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import { Time } from '@/components/geist/Time';

/*
| E5 - the media library (frontend.md §3.5), in Geist's parts (1.10).
|
| The design's table, with a switch to a grid of thumbnails [DECIDED 2026-09-19]. Newest first, and
| paged by keyset: Load More carries the last file's moment and id, so a file uploaded while
| somebody reads never pushes a row onto a page they have already seen.
|
| A **thumbnail is shown only for an image whose variants are ready**. Asked for one still being
| processed, Platform has nothing to give, and a broken picture says less than no picture.
|
| A **delete says where the file is used first**, and Platform refuses it while a use blocks it
| (platform.md §1.4): a company's registration document is not something a tidy-up may remove. The
| screen shows the uses and does not offer the button at all when one of them blocks it.
|
| A **private file** - a company's papers - reaches this page only for a holder of the private-files
| permission, and shows **its name, its upload date and where it is used** (B2B step 3, amendment
| 6): no picture, no type or size, and no retry, since it never has sizes made. It offers Describe
| and Delete to whoever may, as any other file (amendment 8). "Private" is offered when uploading
| only to someone who may also see private files.
*/

type Props = MediaPage;

export default function Index({ media, nextCreatedAt, nextId, mayUpload, mayUpdate, mayDelete, mayUploadPrivate }: Props) {
    const t = useTranslator();
    const [asGrid, setAsGrid] = useState(false);
    const [describing, setDescribing] = useState<string | null>(null);

    return (
        <AdminLayout
            title={t('platform::admin_media.title')}
            subtitle={t('platform::admin_media.subtitle')}
            action={
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="secondary" data-test="view-switch" onClick={() => setAsGrid((grid) => !grid)}>
                        {t(asGrid ? 'platform::admin_media.table' : 'platform::admin_media.grid')}
                    </Button>
                    {mayUpload ? <UploadForm mayUploadPrivate={mayUploadPrivate} /> : null}
                </div>
            }
        >
            <div className="grid gap-4">
                <FormError />

                {media.length === 0 ? (
                    <EmptyState title={t('platform::admin_media.none_title')} description={t('platform::admin_media.none')} />
                ) : asGrid ? (
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {media.map((file) =>
                            isPrivate(file) ? (
                                // Its name, its date and where it is used, in place of the picture
                                // and the size (amendment 6).
                                <li key={file.id} className="material-base grid content-start gap-2 p-2">
                                    <span className="truncate text-label-13 text-ink" title={file.filename}>
                                        {file.filename}
                                    </span>
                                    <span className="text-copy-13 text-ink-muted">
                                        <Time value={file.uploadedAt} />
                                    </span>
                                    <span className="text-copy-13 text-ink-muted">{usedIn(file, t)}</span>
                                </li>
                            ) : (
                                <li key={file.id} className="material-base grid gap-2 p-2">
                                    <Thumbnail file={file} />
                                    <span className="truncate text-label-13 text-ink" title={file.filename}>
                                        {file.filename}
                                    </span>
                                    <span className="tw-figure text-copy-13 text-ink-muted">{file.size}</span>
                                </li>
                            ),
                        )}
                    </ul>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('platform::admin_media.file')}</TableHead>
                                <TableHead>{t('platform::admin_media.type')}</TableHead>
                                <TableHead>{t('platform::admin_media.size')}</TableHead>
                                <TableHead>{t('platform::admin_media.used_in')}</TableHead>
                                <TableHead>{t('platform::admin_media.uploaded')}</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {media.map((file) => (
                                <Row
                                    key={file.id}
                                    file={file}
                                    mayUpdate={mayUpdate}
                                    mayDelete={mayDelete}
                                    describing={describing === file.id}
                                    onDescribe={() => setDescribing(describing === file.id ? null : file.id)}
                                    onDone={() => setDescribing(null)}
                                />
                            ))}
                        </TableBody>
                    </Table>
                )}

                {nextCreatedAt !== null && nextId !== null ? (
                    <LoadMoreButton
                        data-test="more"
                        onClick={() => router.get('/admin/media', { after_at: nextCreatedAt, after_id: nextId })}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}

function Thumbnail({ file }: { file: MediaFileRow }) {
    const t = useTranslator();

    if (file.thumbnailUrl === null) {
        return (
            <span className="grid aspect-square place-items-center overflow-hidden rounded-[var(--tw-radius)] bg-surface-sunken p-1 text-center text-label-12 wrap-anywhere text-ink-muted">
                {file.variantsStatus === null ? file.mime : t(statusKey(file.variantsStatus))}
            </span>
        );
    }

    return (
        <img
            src={file.thumbnailUrl}
            // The description written for it, or nothing: an image with no description is better
            // announced as decorative than with a filename read out letter by letter.
            alt={file.altEn ?? file.altAr ?? ''}
            loading="lazy"
            className="aspect-square w-full rounded-[var(--tw-radius)] border border-line object-cover"
        />
    );
}

function statusKey(status: string): string {
    return `platform::admin_media.variants_${status.toLowerCase()}`;
}

/**
 * A company's papers and the like: listed by name, never shown (amendment 6). Only someone who may
 * see private files is sent one, and they describe or delete it with the usual permissions, as any
 * other file (amendment 8).
 */
function isPrivate(file: MediaFileRow): boolean {
    return file.visibility === 'PRIVATE';
}

function usedIn(file: MediaFileRow, t: (key: string) => string): string {
    return file.usedIn.length === 0 ? t('platform::admin_media.not_used') : file.usedIn.join('، ');
}

type RowProps = {
    file: MediaFileRow;
    mayUpdate: boolean;
    mayDelete: boolean;
    describing: boolean;
    onDescribe: () => void;
    onDone: () => void;
};

function Row({ file, mayUpdate, mayDelete, describing, onDescribe, onDone }: RowProps) {
    const t = useTranslator();

    const alt = useForm({ alt_ar: file.altAr ?? '', alt_en: file.altEn ?? '' });
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);

    function remove() {
        router.post(
            `/admin/media/${file.id}/delete`,
            {},
            {
                onStart: () => setDeleting(true),
                onFinish: () => {
                    setDeleting(false);
                    setConfirming(false);
                },
            },
        );
    }

    return (
        <>
            <TableRow>
                <TableCell>
                    {/* The picture belongs in the table too, not only in the grid (owner,
                        2026-09-24): a library of file names is a list of strings, and the one
                        question somebody has about a file is what it looks like. A private file
                        is the exception: its name only (amendment 6). Its type and size never
                        reach the page, and it offers no retry. */}
                    {isPrivate(file) ? (
                        <span className="text-ink">{file.filename}</span>
                    ) : (
                        <div className="flex items-center gap-3">
                            <span className="w-12 shrink-0">
                                <Thumbnail file={file} />
                            </span>

                            <span className="grid gap-0.5">
                                <span className="text-ink">{file.filename}</span>
                                {file.variantsStatus === null ? null : (
                                    <span className="text-copy-13 text-ink-muted">{t(statusKey(file.variantsStatus))}</span>
                                )}
                            </span>
                        </div>
                    )}
                </TableCell>
                {/* The cell keeps Geist's colour; the quieter type is on what it holds. */}
                <TableCell>
                    <span className="text-copy-13 text-ink-muted">{file.mime}</span>
                </TableCell>
                <TableCell>
                    <span className="tw-figure text-copy-13 text-ink-muted">{file.size}</span>
                </TableCell>
                <TableCell>
                    <span className="text-copy-13 text-ink-muted">{usedIn(file, t)}</span>
                </TableCell>
                <TableCell>
                    <span className="text-copy-13 text-ink-muted">
                        <Time value={file.uploadedAt} />
                    </span>
                </TableCell>
                <TableCell>
                    <div className="flex flex-wrap justify-end gap-2">
                        {file.retryable ? (
                            <Button
                                type="secondary"
                                size="small"
                                onClick={() => router.post(`/admin/media/${file.id}/retry`)}
                            >
                                {t('platform::admin_media.retry')}
                            </Button>
                        ) : null}

                        {mayUpdate ? (
                            <Button type="secondary" size="small" data-test={`describe-${file.id}`} onClick={onDescribe}>
                                {t(describing ? 'platform::admin_media.cancel' : 'platform::admin_media.describe')}
                            </Button>
                        ) : null}

                        {/* Not offered while a use blocks it: a button that always refuses is worse
                            than no button, and the reason is said instead. */}
                        {mayDelete && ! file.deleteBlocked ? (
                            <Button
                                type="error"
                                size="small"
                                data-test={`delete-${file.id}`}
                                onClick={() => setConfirming(true)}
                            >
                                {t('platform::admin_media.delete')}
                            </Button>
                        ) : null}
                    </div>
                </TableCell>
            </TableRow>

            {describing ? (
                <TableRow>
                    <TableCell colSpan={6} className="bg-surface-sunken">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                alt.post(`/admin/media/${file.id}/alt`, { onSuccess: onDone });
                            }}
                            className="grid gap-4 py-1 sm:grid-cols-2"
                        >
                            <Input
                                id={`${file.id}-alt_ar`}
                                label={t('platform::admin_media.alt_ar')}
                                helper={t('platform::admin_media.alt_hint')}
                                error={alt.errors.alt_ar}
                                lang="ar"
                                value={alt.data.alt_ar}
                                onChange={(event) => alt.setData('alt_ar', event.target.value)}
                            />

                            <Input
                                id={`${file.id}-alt_en`}
                                label={t('platform::admin_media.alt_en')}
                                error={alt.errors.alt_en}
                                lang="en"
                                dir="ltr"
                                value={alt.data.alt_en}
                                onChange={(event) => alt.setData('alt_en', event.target.value)}
                            />

                            <div className="sm:col-span-2">
                                <Button typeName="submit" size="small" loading={alt.processing}>
                                    {t('platform::admin_media.save')}
                                </Button>
                            </div>
                        </form>
                    </TableCell>
                </TableRow>
            ) : null}

            {file.deleteBlocked ? (
                <TableRow>
                    <TableCell colSpan={6} className="pt-0">
                        <span className="text-copy-13 text-ink-muted">{t('platform::admin_media.delete_blocked')}</span>
                    </TableCell>
                </TableRow>
            ) : null}

            {/* Asked before deleting rather than with the browser's own confirm box (owner,
                2026-09-24). The spec asks a delete to say where the file is used first, and a
                native box can only carry a sentence - it cannot list them. It also cannot be
                driven by a test, which is why nothing here covered this before. Geist confirms a
                delete in a Modal (frontend.md 1.10); it sits outside the table, drawn over the page. */}
            <Modal
                open={confirming}
                // Never closed from under a delete that is still on its way.
                onOpenChange={(open) => (open || deleting ? undefined : setConfirming(false))}
                destructive
                title={t('platform::admin_media.delete_title')}
                description={t(
                    file.usedIn.length === 0
                        ? 'platform::admin_media.delete_confirm_unused'
                        : 'platform::admin_media.delete_confirm',
                    { count: file.usedIn.length },
                )}
                actions={
                    <>
                        <ModalCancel onClick={() => setConfirming(false)} disabled={deleting} />
                        <Button type="error" loading={deleting} data-test={`delete-confirm-${file.id}`} onClick={remove}>
                            {t('platform::admin_media.delete_title')}
                        </Button>
                    </>
                }
            >
                {file.usedIn.length === 0 ? undefined : (
                    <ul className="grid gap-0.5 text-copy-13 text-ink-muted">
                        {file.usedIn.map((use) => (
                            <li key={use} className="tw-figure">
                                {use}
                            </li>
                        ))}
                    </ul>
                )}
            </Modal>
        </>
    );
}

function UploadForm({ mayUploadPrivate }: { mayUploadPrivate: boolean }) {
    const t = useTranslator();
    const form = useForm<{ file: File | null; visibility: string }>({ file: null, visibility: 'PUBLIC' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                // Nothing to send until a file is chosen, whatever submitted the form.
                if (form.data.file === null) {
                    return;
                }
                form.post('/admin/media', { forceFormData: true, onSuccess: () => form.reset() });
            }}
            className="flex flex-wrap items-center gap-2"
        >
            {/* Geist has no file field; this is the browser's, dressed in Geist's secondary button. */}
            <input
                type="file"
                data-test="file"
                onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                className="text-copy-13 text-ink-muted file:me-2 file:h-8 file:cursor-pointer file:rounded-[var(--tw-radius)] file:border-0 file:bg-surface file:px-2.5 file:text-button-14 file:text-ink file:shadow-[0_0_0_1px_var(--tw-line-strong)] hover:file:bg-surface-sunken"
            />

            <Select
                id="upload-visibility"
                aria-label={t('platform::admin_media.visibility')}
                value={form.data.visibility}
                onChange={(event) => form.setData('visibility', event.target.value)}
            >
                <option value="PUBLIC">{t('platform::admin_media.visibility_public')}</option>
                {/* Only for someone who may also see private files: the upload refuses anyone
                    else (amendment 6). */}
                {mayUploadPrivate ? <option value="PRIVATE">{t('platform::admin_media.visibility_private')}</option> : null}
            </Select>

            <Button
                typeName="submit"
                loading={form.processing}
                disabledReason={form.data.file === null ? t('platform::admin_media.upload_needs_file') : undefined}
            >
                {t('platform::admin_media.upload')}
            </Button>
        </form>
    );
}
