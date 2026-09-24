import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import type { MediaFileRow, MediaPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E5 - the media library (frontend.md §3.5).
|
| The design's table, with a switch to a grid of thumbnails [DECIDED 2026-09-19]. Newest first, and
| paged by keyset: "show more" carries the last file's moment and id, so a file uploaded while
| somebody reads never pushes a row onto a page they have already seen.
|
| A **thumbnail is shown only for an image whose variants are ready**. Asked for one still being
| processed, Platform has nothing to give, and a broken picture says less than no picture.
|
| A **delete says where the file is used first**, and Platform refuses it while a use blocks it
| (platform.md §1.4): a company's registration document is not something a tidy-up may remove. The
| screen shows the uses and does not offer the button at all when one of them blocks it.
*/

type Props = MediaPage;

export default function Index({ media, nextCreatedAt, nextId, mayUpload, mayUpdate, mayDelete }: Props) {
    const t = useTranslator();
    const [asGrid, setAsGrid] = useState(false);
    const [describing, setDescribing] = useState<string | null>(null);

    return (
        <AdminLayout
            title={t('platform::admin_media.title')}
            subtitle={t('platform::admin_media.subtitle')}
            action={
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" data-test="view-switch" onClick={() => setAsGrid((grid) => !grid)}>
                        {t(asGrid ? 'platform::admin_media.table' : 'platform::admin_media.grid')}
                    </Button>
                    {mayUpload ? <UploadForm /> : null}
                </div>
            }
        >
            <div className="grid gap-4">
                <FormError />

                {media.length === 0 ? (
                    <p className="rounded-lg border border-line bg-surface p-6 text-sm text-ink-muted">
                        {t('platform::admin_media.none')}
                    </p>
                ) : asGrid ? (
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {media.map((file) => (
                            <li
                                key={file.id}
                                className="grid gap-2 rounded-lg border border-line bg-surface p-2"
                            >
                                <Thumbnail file={file} />
                                <span className="truncate text-xs text-ink" title={file.filename}>
                                    {file.filename}
                                </span>
                                <span className="tw-figure text-xs text-ink-muted">{file.size}</span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                        <table className="w-full text-sm">
                            <thead className="border-b border-line text-xs text-ink-muted">
                                <tr>
                                    <th className="px-4 py-2 text-start font-medium">
                                        {t('platform::admin_media.file')}
                                    </th>
                                    <th className="px-4 py-2 text-start font-medium">
                                        {t('platform::admin_media.type')}
                                    </th>
                                    <th className="px-4 py-2 text-start font-medium">
                                        {t('platform::admin_media.size')}
                                    </th>
                                    <th className="px-4 py-2 text-start font-medium">
                                        {t('platform::admin_media.used_in')}
                                    </th>
                                    <th className="px-4 py-2 text-start font-medium">
                                        {t('platform::admin_media.uploaded')}
                                    </th>
                                    <th className="px-4 py-2" />
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-line">
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
                            </tbody>
                        </table>
                    </div>
                )}

                {nextCreatedAt !== null && nextId !== null ? (
                    <div>
                        <Button
                            variant="outline"
                            data-test="more"
                            onClick={() =>
                                router.get('/admin/media', { after_at: nextCreatedAt, after_id: nextId })
                            }
                        >
                            {t('platform::admin_media.more')}
                        </Button>
                    </div>
                ) : null}
            </div>
        </AdminLayout>
    );
}

function Thumbnail({ file }: { file: MediaFileRow }) {
    const t = useTranslator();

    if (file.thumbnailUrl === null) {
        return (
            <span className="grid aspect-square place-items-center rounded-md bg-surface-sunken text-center text-[10px] text-ink-muted">
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
            className="aspect-square w-full rounded-md object-cover"
        />
    );
}

function statusKey(status: string): string {
    return `platform::admin_media.variants_${status.toLowerCase()}`;
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

    return (
        <>
            <tr>
                <td className="px-4 py-3">
                    <div className="grid gap-0.5">
                        <span className="text-ink">{file.filename}</span>
                        {file.variantsStatus === null ? null : (
                            <span className="text-xs text-ink-muted">{t(statusKey(file.variantsStatus))}</span>
                        )}
                    </div>
                </td>
                <td className="px-4 py-3 text-xs text-ink-muted">{file.mime}</td>
                <td className="tw-figure px-4 py-3 text-xs text-ink-muted">{file.size}</td>
                <td className="px-4 py-3 text-xs text-ink-muted">
                    {file.usedIn.length === 0 ? t('platform::admin_media.not_used') : file.usedIn.join('، ')}
                </td>
                <td className="tw-figure px-4 py-3 text-xs text-ink-muted">{file.uploadedAt.slice(0, 10)}</td>
                <td className="px-4 py-3">
                    <div className="flex flex-wrap justify-end gap-2">
                        {file.retryable ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.post(`/admin/media/${file.id}/retry`)}
                            >
                                {t('platform::admin_media.retry')}
                            </Button>
                        ) : null}

                        {mayUpdate ? (
                            <Button variant="outline" size="sm" data-test={`describe-${file.id}`} onClick={onDescribe}>
                                {t(describing ? 'platform::admin_media.cancel' : 'platform::admin_media.describe')}
                            </Button>
                        ) : null}

                        {/* Not offered while a use blocks it: a button that always refuses is worse
                            than no button, and the reason is said instead. */}
                        {mayDelete && ! file.deleteBlocked ? (
                            <Button
                                variant="destructive"
                                size="sm"
                                data-test={`delete-${file.id}`}
                                onClick={() => setConfirming((open) => !open)}
                            >
                                {t('platform::admin_media.delete')}
                            </Button>
                        ) : null}
                    </div>
                </td>
            </tr>

            {describing ? (
                <tr>
                    <td colSpan={6} className="bg-surface-sunken px-4 py-4">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                alt.post(`/admin/media/${file.id}/alt`, { onSuccess: onDone });
                            }}
                            className="grid gap-4 sm:grid-cols-2"
                        >
                            <Field
                                id={`${file.id}-alt_ar`}
                                label={t('platform::admin_media.alt_ar')}
                                hint={t('platform::admin_media.alt_hint')}
                                error={alt.errors.alt_ar}
                            >
                                <Input
                                    id={`${file.id}-alt_ar`}
                                    lang="ar"
                                    value={alt.data.alt_ar}
                                    onChange={(event) => alt.setData('alt_ar', event.target.value)}
                                />
                            </Field>

                            <Field
                                id={`${file.id}-alt_en`}
                                label={t('platform::admin_media.alt_en')}
                                error={alt.errors.alt_en}
                            >
                                <Input
                                    id={`${file.id}-alt_en`}
                                    lang="en"
                                    dir="ltr"
                                    value={alt.data.alt_en}
                                    onChange={(event) => alt.setData('alt_en', event.target.value)}
                                />
                            </Field>

                            <div className="sm:col-span-2">
                                <Button type="submit" size="sm" disabled={alt.processing}>
                                    {t('platform::admin_media.save')}
                                </Button>
                            </div>
                        </form>
                    </td>
                </tr>
            ) : null}

            {/* Asked in the page rather than with the browser's own confirm box (owner,
                2026-09-24). The spec asks a delete to say where the file is used first, and a
                native box can only carry a sentence - it cannot list them. It also cannot be
                driven by a test, which is why nothing here covered this before. */}
            {confirming ? (
                <tr>
                    <td colSpan={6} className="bg-bad-soft px-4 py-4">
                        <div className="grid gap-3">
                            <p className="text-sm text-ink">
                                {t(
                                    file.usedIn.length === 0
                                        ? 'platform::admin_media.delete_confirm_unused'
                                        : 'platform::admin_media.delete_confirm',
                                    { count: file.usedIn.length },
                                )}
                            </p>

                            {file.usedIn.length === 0 ? null : (
                                <ul className="grid gap-0.5 text-xs text-ink-muted">
                                    {file.usedIn.map((use) => (
                                        <li key={use} className="tw-figure">
                                            {use}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    data-test={`delete-confirm-${file.id}`}
                                    onClick={() => router.post(`/admin/media/${file.id}/delete`)}
                                >
                                    {t('platform::admin_media.delete')}
                                </Button>

                                <Button variant="outline" size="sm" onClick={() => setConfirming(false)}>
                                    {t('platform::admin_media.cancel')}
                                </Button>
                            </div>
                        </div>
                    </td>
                </tr>
            ) : null}

            {file.deleteBlocked ? (
                <tr>
                    <td colSpan={6} className="px-4 pb-3 text-xs text-ink-muted">
                        {t('platform::admin_media.delete_blocked')}
                    </td>
                </tr>
            ) : null}
        </>
    );
}

function UploadForm() {
    const t = useTranslator();
    const form = useForm<{ file: File | null; visibility: string }>({ file: null, visibility: 'PUBLIC' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/admin/media', { forceFormData: true, onSuccess: () => form.reset() });
            }}
            className="flex flex-wrap items-center gap-2"
        >
            <input
                type="file"
                data-test="file"
                onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                className="text-xs text-ink-muted file:me-2 file:rounded-md file:border file:border-line file:bg-surface file:px-2 file:py-1 file:text-ink"
            />

            <select
                value={form.data.visibility}
                onChange={(event) => form.setData('visibility', event.target.value)}
                aria-label={t('platform::admin_media.visibility')}
                className="h-9 rounded-md border border-line-strong bg-surface px-2 text-xs text-ink"
            >
                <option value="PUBLIC">{t('platform::admin_media.visibility_public')}</option>
                <option value="PRIVATE">{t('platform::admin_media.visibility_private')}</option>
            </select>

            <Button type="submit" disabled={form.processing || form.data.file === null}>
                {t('platform::admin_media.upload')}
            </Button>
        </form>
    );
}
