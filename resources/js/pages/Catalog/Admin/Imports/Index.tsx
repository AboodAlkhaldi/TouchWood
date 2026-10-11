import { useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Time } from '@/components/Time';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ImportFileRowData, ImportProblemData, ImportsPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { useLocale } from '../parts';
import { GuideLink, ImportStateBadge, ProblemList } from './parts';

/*
| The Super Admin's products files (catalog.md §4.4 S11): newest first - name · who uploaded it
| (Platform's StaffNames) · when · products · state - with Show More for the next page. **Upload
| Products File** takes a `.json` (20 MB) or a `.zip` with `products.json` at its top and its photos
| (500 MB), 2,000 products a file, and opens its page; a refused file lists every problem under the
| upload, as a store file's does (S10). The files' guide is linked.
*/

export default function Index({ files, page, more, problems, problemsTotal }: ImportsPage) {
    const t = useTranslator();
    const locale = useLocale();
    const [uploading, setUploading] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const list = useLoadMore<ImportFileRowData>(files, (file) => file.id);
    const upload = (trigger: HTMLElement) => {
        opener.current = trigger;
        setUploading(true);
    };

    return (
        <AdminLayout
            title={t('catalog::admin_imports.title')}
            subtitle={t('catalog::admin_imports.subtitle')}
            action={
                <ActionButton type="button" onClick={(event) => upload(event.currentTarget)} data-test="upload-import">
                    {t('catalog::admin_imports.upload')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <GuideLink />

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="imports-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_imports.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_imports.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={(event) => upload(event.currentTarget)}>
                                {t('catalog::admin_imports.upload')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="grid gap-3">
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('catalog::admin_imports.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('catalog::admin_imports.column.file')}</TableHead>
                                        <TableHead>{t('catalog::admin_imports.column.uploaded_by')}</TableHead>
                                        <TableHead>{t('catalog::admin_imports.column.uploaded')}</TableHead>
                                        <TableHead className="text-end">{t('catalog::admin_imports.column.products')}</TableHead>
                                        <TableHead>{t('catalog::admin_imports.column.state')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((file) => (
                                        <TableRow key={file.id} className="relative" data-test={`import-${file.id}`}>
                                            <TableCell>
                                                <Link href={`/admin/imports/${file.id}`} className="text-label-14 text-ink after:absolute after:inset-0 focus-visible:after:ring-[3px]">
                                                    <bdi>{file.fileName}</bdi>
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-copy-14">{file.uploadedBy ?? '—'}</TableCell>
                                            <TableCell>
                                                <Time value={file.uploadedAt} />
                                            </TableCell>
                                            <TableCell className="tw-figure text-end">{figure(locale, file.products)}</TableCell>
                                            <TableCell>
                                                <ImportStateBadge state={file.state} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {more ? <LoadMoreButton loading={list.loading} onClick={() => list.more(window.location.pathname, { page: page + 1 }, ['files', 'page', 'more'])} /> : null}
                    </div>
                )}
            </div>

            {uploading ? <UploadDialog problems={problems} problemsTotal={problemsTotal} onOpenChange={setUploading} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function UploadDialog({
    problems,
    problemsTotal,
    onOpenChange,
    returnFocusTo,
}: {
    problems: ImportProblemData[] | null;
    problemsTotal: number | null;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<{ file: File | null }>({ file: null });
    // Only the problems answering this dialog's own upload: the page's are the last upload's.
    const [sent, setSent] = useState(false);

    function submit() {
        setSent(true);
        form.post('/admin/imports', { forceFormData: true, preserveScroll: true });
    }

    return (
        <PanelDialog
            wide
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_imports.upload_title')}
            description={t('catalog::admin_imports.upload_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={form.data.file === null ? t('catalog::admin_store_files.upload_none') : undefined} onClick={submit} data-test="confirm-upload">
                    {t('catalog::admin_imports.upload_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <Field>
                    <FieldLabel htmlFor="import-file">{t('catalog::admin_imports.upload_field')}</FieldLabel>
                    <Input
                        id="import-file"
                        type="file"
                        accept="application/json,.json,application/zip,.zip"
                        aria-invalid={form.errors.file ? true : undefined}
                        aria-describedby={form.errors.file ? 'import-file-error' : undefined}
                        onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                        data-test="import-file"
                    />
                    {form.errors.file ? <FieldError id="import-file-error">{form.errors.file}</FieldError> : null}
                </Field>
                <GuideLink />
                {sent && problems !== null && problems.length > 0 ? <ProblemList problems={problems} total={problemsTotal} /> : null}
            </div>
        </PanelDialog>
    );
}
