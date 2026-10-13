import { useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ImportProblemData, StoreFileRowData, StoreFilesPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { GuideLink } from '../Imports/parts';
import { useLocale } from '../parts';

/*
| A store's files (catalog.md §4.4 S10): the admins' job (`catalog.listing.fill`, admin roles only),
| the store chosen in the page's own filter (`?store=`). Newest first: the file's name · when · items ·
| items still open, Show More for the next page. **Upload Store File** takes a `.json` of
| `touchwood-store-fill/1` (2 MB, 1,000 items) and opens its page; a refused file lists every
| problem - where, and what, in the format's own words - under the upload.
*/

/** At most this many problems come back with a refused file (StoreFilesController); past it, the page says so. */
const PROBLEMS_SHOWN = 500;

export default function Index({ files, page, more, storeCode, storeName, stores, problems, problemsTotal }: StoreFilesPage) {
    const t = useTranslator();
    const locale = useLocale();
    const [uploading, setUploading] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const list = useLoadMore<StoreFileRowData>(files, (file) => file.id);
    const upload = (trigger: HTMLElement) => {
        opener.current = trigger;
        setUploading(true);
    };

    return (
        <AdminLayout
            title={t('catalog::admin_store_files.title')}
            subtitle={t('catalog::admin_store_files.subtitle', { store: storeName })}
            action={
                <ActionButton type="button" onClick={(event) => upload(event.currentTarget)} data-test="upload-store-file">
                    {t('catalog::admin_store_files.upload')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <StoreFilter stores={stores} value={storeCode} className="max-w-xs" />

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="store-files-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_store_files.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_store_files.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={(event) => upload(event.currentTarget)}>
                                {t('catalog::admin_store_files.upload')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="grid gap-3">
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('catalog::admin_store_files.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('catalog::admin_store_files.column.file')}</TableHead>
                                        <TableHead>{t('catalog::admin_store_files.column.uploaded')}</TableHead>
                                        <TableHead className="text-end">{t('catalog::admin_store_files.column.items')}</TableHead>
                                        <TableHead className="text-end">{t('catalog::admin_store_files.column.open')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((file) => (
                                        <TableRow key={file.id} className="relative" data-test={`store-file-${file.id}`}>
                                            <TableCell>
                                                <Link href={`/admin/store-files/${file.id}`} className="text-label-14 text-ink after:absolute after:inset-0 focus-visible:after:ring-[3px]">
                                                    <bdi>{file.fileName}</bdi>
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <Time value={file.uploadedAt} />
                                            </TableCell>
                                            <TableCell className="tw-figure text-end">{figure(locale, file.items)}</TableCell>
                                            <TableCell className="tw-figure text-end">{figure(locale, file.open)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {more ? <LoadMoreButton loading={list.loading} onClick={() => list.more(window.location.pathname, { page: page + 1, store: storeCode }, ['files', 'page', 'more'])} /> : null}
                    </div>
                )}
            </div>

            {uploading ? <UploadDialog storeCode={storeCode} storeName={storeName} problems={problems} problemsTotal={problemsTotal} onOpenChange={setUploading} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function UploadDialog({
    storeCode,
    storeName,
    problems,
    problemsTotal,
    onOpenChange,
    returnFocusTo,
}: {
    storeCode: string;
    storeName: string;
    problems: ImportProblemData[] | null;
    problemsTotal: number | null;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<{ store: string; file: File | null }>({ store: storeCode, file: null });
    // Only the problems answering this dialog's own upload: the page's are the last upload's.
    const [sent, setSent] = useState(false);
    const shown = sent && problems !== null ? problems : [];

    function submit() {
        setSent(true);
        form.post('/admin/store-files', { forceFormData: true, preserveScroll: true });
    }

    return (
        <PanelDialog
            wide
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_store_files.upload_title')}
            description={t('catalog::admin_store_files.upload_body', { store: storeName })}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={form.data.file === null ? t('catalog::admin_store_files.upload_none') : undefined} onClick={submit} data-test="confirm-upload">
                    {t('catalog::admin_store_files.upload_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <Field>
                    <FieldLabel htmlFor="store-file">{t('catalog::admin_store_files.upload_field')}</FieldLabel>
                    <Input
                        id="store-file"
                        type="file"
                        accept="application/json,.json"
                        aria-invalid={form.errors.file ? true : undefined}
                        aria-describedby={form.errors.file ? 'store-file-error' : undefined}
                        onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                        data-test="store-file"
                    />
                    {form.errors.file ? <FieldError id="store-file-error">{form.errors.file}</FieldError> : null}
                </Field>
                <GuideLink />
                {shown.length > 0 ? (
                    <div className="grid gap-2" data-test="file-problems">
                        <p className="text-label-14 text-ink">{t('catalog::admin_store_files.problems_title')}</p>
                        {/* The format's own words, English as the format is (Catalog README, S6). */}
                        <ol className="grid list-decimal gap-1 ps-5 text-copy-13 text-ink" dir="ltr">
                            {shown.map((problem, index) => (
                                <li key={`${index}-${problem.at}`}>
                                    <span className="font-mono">{problem.at}</span>: {problem.problem}
                                </li>
                            ))}
                        </ol>
                        {(problemsTotal ?? 0) > PROBLEMS_SHOWN ? <p className="text-copy-13 text-ink-muted">{t('catalog::admin_store_files.problems_more')}</p> : null}
                    </div>
                ) : null}
            </div>
        </PanelDialog>
    );
}
