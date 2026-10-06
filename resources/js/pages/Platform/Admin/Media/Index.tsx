import { type RefObject, useMemo, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { FileText, ImageOff, MoreHorizontal } from 'lucide-react';
import { cn } from 'cn';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { DialogError, FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Time } from '@/components/Time';
import { MiddleTruncate } from '@/components/geist-only/MiddleTruncate';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Attachment, AttachmentContent, AttachmentDescription, AttachmentMedia, AttachmentTitle } from '@/components/ui/attachment';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ItemMedia } from '@/components/ui/item';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Progress } from '@/components/ui/progress';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { fileSize } from '@/lib/file-size';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useLoadMore } from '@/lib/use-load-more';
import { useReturnFocus } from '@/lib/use-return-focus';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { MediaFileRow, MediaPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| E5 - the media library (frontend.md §3.5), on shadcn's parts with Geist's rules (§1.11).
|
| The design's table, with a switch to a grid of thumbnails [DECIDED 2026-09-19] - Geist's Switch
| for two views of one surface, on shadcn's ToggleGroup, so the view shown is the one pressed.
| Newest first, and paged by keyset: Show More carries the last file's moment and id, so a file
| uploaded while somebody reads never pushes a row onto a page they have already seen, and adds the
| next page under the rows already shown (owner, 2026-10-04).
|
| A **thumbnail is shown only for an image whose variants are ready**. Asked for one still being
| processed, Platform has nothing to give, and a broken picture says less than no picture. Whether
| sizes are being made, or failed, is a Badge with its word (Geist: colour never alone).
|
| A row holds one control, a ⋯ menu (Geist's Entity: past two controls, a Dots Menu): Retry
| Processing, Edit Description…, and Delete File… last, after a divider. **A delete says where the
| file is used first**, and Platform refuses it while a use blocks it (platform.md §1.4): the item
| stays in the menu, out of reach, with the reason written under it (the rebuild spec: a Delete that
| cannot be done is shown disabled, with its reason - inside a menu the reason is written, since a
| tooltip there cannot be reached by touch).
|
| Describing a file is a Dialog with the two fields (owner's #9, 2026-10-02). Uploading is a card of
| its own above the list, with a named file field and a progress bar while the file goes up (Geist's
| Progress: "determinate work whose total is knowable, like file uploads").
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
    const [view, setView] = useState<'table' | 'grid'>('table');
    const list = useLoadMore(media, (file) => file.id);
    // Where focus goes once a deleted file's row, and its ⋯ button, are gone.
    const top = useRef<HTMLDivElement>(null);

    return (
        <AdminLayout
            title={t('platform::admin_media.title')}
            subtitle={t('platform::admin_media.subtitle')}
            action={
                // Geist's Switch: a sunken track with the chosen view raised on it.
                <ToggleGroup
                    type="single"
                    value={view}
                    // A pressed item pressed again would clear it; a view is always one of the two.
                    onValueChange={(next) => (next === 'table' || next === 'grid' ? setView(next) : undefined)}
                    aria-label={t('platform::admin_media.view')}
                    data-test="view-switch"
                    className={SWITCH_TRACK}
                >
                    <ToggleGroupItem value="table" data-test="view-table" className={SWITCH_ITEM}>
                        {t('platform::admin_media.table')}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="grid" data-test="view-grid" className={SWITCH_ITEM}>
                        {t('platform::admin_media.grid')}
                    </ToggleGroupItem>
                </ToggleGroup>
            }
        >
            <div ref={top} tabIndex={-1} className="grid gap-4 outline-none">
                <FormError />

                {mayUpload ? <UploadCard mayUploadPrivate={mayUploadPrivate} /> : null}

                {list.rows.length === 0 ? (
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('platform::admin_media.none_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('platform::admin_media.none')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : view === 'grid' ? (
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" aria-label={t('platform::admin_media.title')}>
                        {list.rows.map((file) => (
                            <li key={file.id}>
                                <Tile file={file} />
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div className="material-base overflow-hidden">
                        <Table>
                            <TableCaption className="sr-only">{t('platform::admin_media.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('platform::admin_media.file')}</TableHead>
                                    <TableHead>{t('platform::admin_media.type')}</TableHead>
                                    <TableHead className="text-end">{t('platform::admin_media.size')}</TableHead>
                                    <TableHead>{t('platform::admin_media.used_in')}</TableHead>
                                    <TableHead>{t('platform::admin_media.uploaded')}</TableHead>
                                    <TableHead>
                                        <span className="sr-only">{t('platform::admin_media.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((file) => (
                                    <Row key={file.id} file={file} mayUpdate={mayUpdate} mayDelete={mayDelete} listTop={top} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {nextCreatedAt !== null && nextId !== null ? (
                    <LoadMoreButton loading={list.loading} onClick={() => list.more('/admin/media', { after_at: nextCreatedAt, after_id: nextId }, ['media', 'nextCreatedAt', 'nextId'])} />
                ) : null}
            </div>
        </AdminLayout>
    );
}

/** Whether sizes are being made or failed, as a Badge with its word (amber at work, red failed). */
function SizesBadge({ file }: { file: MediaFileRow }) {
    const t = useTranslator();

    if (file.variantsStatus === null || file.variantsStatus === 'READY') {
        return null;
    }

    return (
        <Badge className={tone(file.variantsStatus === 'FAILED' ? 'red-subtle' : 'amber-subtle')}>
            {file.variantsStatus === 'FAILED' ? t('platform::admin_media.variants_failed') : t('platform::admin_media.variants_pending')}
        </Badge>
    );
}

/** A value that is not known or not shown: Geist's em dash. */
function Unknown() {
    return <span className="text-ink-subtle">—</span>;
}

/** A file's size in the page's language, in Latin digits (frontend.md §1.8); none known, an em dash. */
function Size({ bytes }: { bytes: number | null }) {
    const { locale } = usePage<SharedProps>().props;

    return bytes === null ? <Unknown /> : <>{fileSize(bytes, locale)}</>;
}

/** The picture, when there is one ready to show; otherwise a plain mark, never a broken image. */
function Picture({ file }: { file: MediaFileRow }) {
    if (file.thumbnailUrl === null) {
        return isPrivate(file) || !(file.mime ?? '').startsWith('image/') ? <FileText aria-hidden="true" /> : <ImageOff aria-hidden="true" />;
    }

    // The description written for it, or nothing: an image with no description is better announced
    // as decorative than with a filename read out letter by letter.
    return <img src={file.thumbnailUrl} alt={file.altEn ?? file.altAr ?? ''} loading="lazy" />;
}

/** One file in the grid: shadcn's Attachment, upright, its name cut in the middle (Geist). */
function Tile({ file }: { file: MediaFileRow }) {
    return (
        <Attachment orientation="vertical" state={file.variantsStatus === 'FAILED' ? 'error' : 'done'} className="material-base w-full border-0 has-data-[slot=attachment-content]:w-full">
            <AttachmentMedia variant={file.thumbnailUrl === null ? 'icon' : 'image'}>
                <Picture file={file} />
            </AttachmentMedia>
            <AttachmentContent className="grid gap-1">
                {/* Its own cut, not the title's end ellipsis: Geist's Middle Truncate is never wrapped in another. */}
                <AttachmentTitle className="text-clip text-label-13 text-ink">
                    <MiddleTruncate value={file.filename} />
                </AttachmentTitle>
                <AttachmentDescription className="text-copy-13 text-ink-muted">
                    {isPrivate(file) ? (
                        // Its name, its date and where it is used, in place of the size (amendment 6).
                        <>
                            <Time value={file.uploadedAt} />
                            <span className="block truncate">
                                <UsedIn file={file} />
                            </span>
                        </>
                    ) : (
                        <span className="tw-figure">
                            <Size bytes={file.bytes} />
                        </span>
                    )}
                </AttachmentDescription>
                <SizesBadge file={file} />
            </AttachmentContent>
        </Attachment>
    );
}

/**
 * A company's papers and the like: listed by name, never shown (amendment 6). Only someone who may
 * see private files is sent one, and they describe or delete it with the usual permissions, as any
 * other file (amendment 8).
 */
function isPrivate(file: MediaFileRow): boolean {
    return file.visibility === 'PRIVATE';
}

/** Where a file is used, each use isolated: a use is a name or a code, in either language. */
function UsedIn({ file }: { file: MediaFileRow }) {
    const t = useTranslator();

    return file.usedIn.length === 0 ? (
        <>{t('platform::admin_media.not_used')}</>
    ) : (
        <>
            {file.usedIn.map((use, index) => (
                <span key={use}>
                    {index === 0 ? null : ' · '}
                    <bdi>{use}</bdi>
                </span>
            ))}
        </>
    );
}

function Row({ file, mayUpdate, mayDelete, listTop }: { file: MediaFileRow; mayUpdate: boolean; mayDelete: boolean; listTop: RefObject<HTMLDivElement | null> }) {
    const t = useTranslator();
    const [describing, setDescribing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const describeFocus = useReturnFocus(describing, more);
    // The row's ⋯ button while the row is there; the list once the file is deleted (the review of batch D).
    const afterDelete = useMemo<RefObject<HTMLElement | null>>(() => ({ get current() { return more.current?.isConnected ? more.current : listTop.current; } }), [listTop]);
    const deleteFocus = useReturnFocus(confirming, afterDelete);
    const alt = useForm({ alt_ar: file.altAr ?? '', alt_en: file.altEn ?? '' });
    const hasMenu = file.retryable || mayUpdate || mayDelete;

    function remove() {
        router.post(
            `/admin/media/${file.id}/delete`,
            {},
            {
                onStart: () => setDeleting(true),
                // Closed once it is gone; a refusal keeps it open, with the reason inside it.
                onSuccess: () => setConfirming(false),
                onFinish: () => setDeleting(false),
            },
        );
    }

    return (
        <TableRow>
            <TableCell>
                {/* The picture belongs in the table too, not only in the grid (owner, 2026-09-24):
                    the one question somebody has about a file is what it looks like. A private file
                    is the exception: its name only (amendment 6). */}
                {/* The width is capped here, not on the cell, which a table's layout ignores: past
                    it, the name is cut in the middle (the review of batch D). */}
                <div className="flex w-72 max-w-72 min-w-0 items-center gap-3">
                    {isPrivate(file) ? null : (
                        <ItemMedia variant={file.thumbnailUrl === null ? 'icon' : 'image'} className="size-12 border-line bg-surface-sunken text-ink-muted [&_svg:not([class*='size-'])]:size-5">
                            <Picture file={file} />
                        </ItemMedia>
                    )}
                    <span className="grid min-w-0 gap-1">
                        <MiddleTruncate value={file.filename} className="text-ink" />
                        <span>
                            <SizesBadge file={file} />
                        </span>
                    </span>
                </div>
            </TableCell>
            {/* What a private file is stays with those who may see it: an em dash, Geist's unknown. */}
            <TableCell className="text-copy-13 text-ink-muted">{isPrivate(file) || file.mime === null ? <Unknown /> : <bdi dir="ltr">{file.mime}</bdi>}</TableCell>
            <TableCell className="tw-figure text-end text-copy-13 text-ink-muted">{isPrivate(file) ? <Unknown /> : <Size bytes={file.bytes} />}</TableCell>
            <TableCell className="max-w-56 whitespace-normal text-copy-13 text-ink-muted">
                <UsedIn file={file} />
            </TableCell>
            <TableCell className="text-copy-13 text-ink-muted">
                <Time value={file.uploadedAt} />
            </TableCell>
            <TableCell className="text-end">
                {hasMenu ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                ref={more}
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`${t('ui.more_actions')}: ${file.filename}`}
                                aria-busy={retrying || undefined}
                                title={t('ui.more_actions')}
                                data-test={`file-menu-${file.id}`}
                            >
                                <MoreHorizontal aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            {file.retryable ? (
                                <DropdownMenuItem
                                    disabled={retrying}
                                    data-test={`retry-${file.id}`}
                                    onSelect={() => router.post(`/admin/media/${file.id}/retry`, {}, { preserveScroll: true, onStart: () => setRetrying(true), onFinish: () => setRetrying(false) })}
                                >
                                    {t('platform::admin_media.retry')}
                                </DropdownMenuItem>
                            ) : null}
                            {mayUpdate ? (
                                <DropdownMenuItem data-test={`describe-${file.id}`} onSelect={() => setDescribing(true)}>
                                    {t('platform::admin_media.describe_open')}
                                </DropdownMenuItem>
                            ) : null}
                            {mayDelete ? (
                                <>
                                    {file.retryable || mayUpdate ? <DropdownMenuSeparator /> : null}
                                    {/* Out of reach while a use keeps the file, its reason written
                                        under it: still in the menu, so nobody wonders where it went. */}
                                    <DropdownMenuItem
                                        variant="destructive"
                                        aria-disabled={file.deleteBlocked || undefined}
                                        data-test={`delete-${file.id}`}
                                        className={cn(file.deleteBlocked && 'cursor-not-allowed')}
                                        onSelect={(event) => {
                                            if (file.deleteBlocked) {
                                                event.preventDefault();

                                                return;
                                            }
                                            setConfirming(true);
                                        }}
                                    >
                                        <span className="grid gap-0.5">
                                            {/* The name dimmed; the reason in full ink, as on the roles page. */}
                                            <span className={file.deleteBlocked ? 'opacity-60' : undefined}>{t('platform::admin_media.delete_open')}</span>
                                            {file.deleteBlocked ? <span className="text-copy-12 text-ink-muted">{t('platform::admin_media.delete_blocked')}</span> : null}
                                        </span>
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}

                {/* Describing: a Dialog with the two fields (owner's #9, 2026-10-02). */}
                <Dialog open={describing} onOpenChange={(open) => (alt.processing ? undefined : setDescribing(open))}>
                    <DialogContent showCloseButton={false} onCloseAutoFocus={describeFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 text-start sm:max-w-md">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                alt.post(`/admin/media/${file.id}/alt`, { preserveScroll: true, onSuccess: () => setDescribing(false) });
                            }}
                        >
                            <div className="grid gap-4 p-6">
                                <DialogHeader>
                                    <DialogTitle className="text-heading-20 text-ink">{t('platform::admin_media.describe')}</DialogTitle>
                                    <DialogDescription className="text-copy-14 text-ink-muted">{t('platform::admin_media.alt_hint')}</DialogDescription>
                                </DialogHeader>
                                <DialogError open={describing} />
                                <TextField
                                    id={`${file.id}-alt_ar`}
                                    label={t('platform::admin_media.alt_ar')}
                                    error={alt.errors.alt_ar}
                                    lang="ar"
                                    dir="rtl"
                                    value={alt.data.alt_ar}
                                    onChange={(event) => alt.setData('alt_ar', event.target.value)}
                                />
                                <TextField
                                    id={`${file.id}-alt_en`}
                                    label={t('platform::admin_media.alt_en')}
                                    error={alt.errors.alt_en}
                                    lang="en"
                                    dir="ltr"
                                    value={alt.data.alt_en}
                                    onChange={(event) => alt.setData('alt_en', event.target.value)}
                                />
                            </div>
                            <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                                <Button type="button" variant="outline" disabled={alt.processing} onClick={() => setDescribing(false)}>
                                    {t('ui.cancel')}
                                </Button>
                                <ActionButton type="submit" loading={alt.processing} data-test={`save-description-${file.id}`}>
                                    {t('platform::admin_media.save')}
                                </ActionButton>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Asked before deleting rather than with the browser's own confirm box (owner,
                    2026-09-24). The spec asks a delete to say where the file is used first, and a
                    native box can only carry a sentence - it cannot list them. */}
                <AlertDialog open={confirming} onOpenChange={(open) => (open || deleting ? undefined : setConfirming(false))}>
                    <AlertDialogContent onCloseAutoFocus={deleteFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 text-start data-[size=default]:sm:max-w-md">
                        <div className="grid gap-4 p-6">
                            <AlertDialogHeader>
                                <AlertDialogTitle className="text-heading-20 text-ink">{t('platform::admin_media.delete_title')}</AlertDialogTitle>
                                <AlertDialogDescription className="text-copy-14 text-ink-muted">
                                    {file.usedIn.length === 0 ? t('platform::admin_media.delete_confirm_unused') : t('platform::admin_media.delete_confirm', { count: file.usedIn.length })}
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            {file.usedIn.length === 0 ? null : (
                                <ul className="grid gap-0.5 text-copy-13 text-ink-muted">
                                    {file.usedIn.map((use) => (
                                        <li key={use}>
                                            <bdi>{use}</bdi>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <DialogError open={confirming} />
                        </div>
                        <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                            <AlertDialogCancel disabled={deleting} data-test="modal-cancel">
                                {t('ui.cancel')}
                            </AlertDialogCancel>
                            <ActionButton variant="destructive" loading={deleting} data-test={`delete-confirm-${file.id}`} onClick={remove}>
                                {t('platform::admin_media.delete_title')}
                            </ActionButton>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </TableCell>
        </TableRow>
    );
}

/** Uploading: a card of its own (Geist's Fieldset), the file field named, a bar while it goes up. */
function UploadCard({ mayUploadPrivate }: { mayUploadPrivate: boolean }) {
    const t = useTranslator();
    const form = useForm<{ file: File | null; visibility: string }>({ file: null, visibility: 'PUBLIC' });
    const picker = useRef<HTMLInputElement>(null);

    return (
        <Card className="material-base gap-0 border-0 py-0">
            <form
                aria-labelledby="upload-title"
                onSubmit={(event) => {
                    event.preventDefault();
                    // Nothing to send until a file is chosen, whatever submitted the form.
                    if (form.data.file === null) {
                        return;
                    }
                    form.post('/admin/media', {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            // The browser's own field keeps its file name until it is cleared.
                            if (picker.current !== null) {
                                picker.current.value = '';
                            }
                        },
                    });
                }}
            >
                <CardHeader className="px-5 pt-5 pb-4">
                    <CardTitle>
                        <h2 id="upload-title" className="text-heading-16 text-ink">
                            {t('platform::admin_media.upload')}
                        </h2>
                    </CardTitle>
                </CardHeader>
                <CardContent className="grid gap-5 px-5 pb-5 sm:grid-cols-2">
                    <Field>
                        <FieldLabel htmlFor="upload-file">{t('platform::admin_media.file')}</FieldLabel>
                        <Input id="upload-file" ref={picker} type="file" data-test="file" onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} />
                    </Field>
                    <SelectField
                        id="upload-visibility"
                        label={t('platform::admin_media.visibility')}
                        value={form.data.visibility}
                        error={form.errors.visibility}
                        onChange={(event) => form.setData('visibility', event.target.value)}
                    >
                        <NativeSelectOption value="PUBLIC">{t('platform::admin_media.visibility_public')}</NativeSelectOption>
                        {/* Only for someone who may also see private files: the upload refuses
                            anyone else (amendment 6). */}
                        {mayUploadPrivate ? <NativeSelectOption value="PRIVATE">{t('platform::admin_media.visibility_private')}</NativeSelectOption> : null}
                    </SelectField>
                    {form.progress === null || form.progress?.percentage === undefined ? null : (
                        <div className="grid gap-1 sm:col-span-2">
                            <Progress value={form.progress.percentage} aria-labelledby="upload-progress" />
                            <span id="upload-progress" className="tw-figure text-copy-13 text-ink-muted">
                                {t('platform::admin_media.uploading', { percent: form.progress.percentage })}
                            </span>
                        </div>
                    )}
                </CardContent>
                <CardFooter className={cn('justify-end border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3')}>
                    <ActionButton type="submit" loading={form.processing} disabledReason={form.data.file === null ? t('platform::admin_media.upload_needs_file') : undefined} data-test="upload">
                        {t('platform::admin_media.upload')}
                    </ActionButton>
                </CardFooter>
            </form>
        </Card>
    );
}
