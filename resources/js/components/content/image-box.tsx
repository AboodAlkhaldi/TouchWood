import { lazy, Suspense, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ImageIcon } from 'lucide-react';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';
import type { ContentImageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| An item's upload box (content.md §4.2, §1.6): a shadcn `Button` and the file picker, the thumbnail once the file's sizes
| are made, its name, the size to upload under the box, Remove, and shadcn's `Progress` while it uploads. An image under
| the size it is shown at, at double density, is warned of, never refused (§1.6). The file is uploaded at once under the
| item's own permission (`POST /admin/content/uploads/{kind}`); the item names it only when its form is saved.
*/

// The bar shows only while a file uploads, never on first paint: loaded then (lesson 180; JavaScriptBudgetTest).
const Progress = lazy(() => import('@/components/ui/progress').then((module) => ({ default: module.Progress })));

type Props = {
    id: string;
    label: string;
    /** The upload kind: the item's permission is checked in its store. */
    kind: string;
    store: string;
    image: ContentImageData | null;
    onChange: (image: ContentImageData | null) => void;
    /** The size to upload (§1.6), said under the box; an image under it is warned of. */
    size?: { width: number; height: number; png?: boolean };
    helper?: string;
    error?: string;
};

export function ImageBox({ id, label, kind, store, image, onChange, size, helper, error }: Props) {
    const t = useTranslator();
    const { csrfToken } = usePage<SharedProps>().props;
    const picker = useRef<HTMLInputElement>(null);
    const [percent, setPercent] = useState<number | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const note = size === undefined ? helper : [size.png ? t('content::admin_shared.image.size_png', { width: size.width, height: size.height }) : t('content::admin_shared.image.size', { width: size.width, height: size.height }), helper].filter(Boolean).join(' ');
    const small = image !== null && size !== undefined && image.width !== null && image.height !== null && (image.width < size.width || image.height < size.height);
    const shown = failed ?? error;

    const upload = (file: File) => {
        const body = new FormData();
        body.append('file', file);
        body.append('store', store);
        const request = new XMLHttpRequest();
        request.open('POST', `/admin/content/uploads/${kind}`);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-CSRF-TOKEN', csrfToken);
        request.upload.onprogress = (event) => setPercent(event.lengthComputable ? Math.round((event.loaded / event.total) * 100) : 0);
        request.onload = () => {
            setPercent(null);
            let answer: (ContentImageData & { detail?: string }) | null = null;

            try {
                answer = JSON.parse(request.responseText) as ContentImageData & { detail?: string };
            } catch {
                answer = null;
            }

            if (request.status === 201 && answer !== null && typeof answer.id === 'string') {
                setFailed(null);
                onChange(answer);
            } else {
                setFailed(typeof answer?.detail === 'string' ? answer.detail : t('content::admin_shared.image.upload_failed'));
            }
        };
        request.onerror = () => {
            setPercent(null);
            setFailed(t('content::admin_shared.image.upload_failed'));
        };
        setFailed(null);
        setPercent(0);
        request.send(body);
    };

    return (
        <Field data-invalid={shown ? true : undefined} data-test={`image-${id}`}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <div className="flex min-w-0 flex-wrap items-center gap-3">
                <div className="material-small flex size-16 shrink-0 items-center justify-center overflow-hidden bg-surface-sunken">
                    {image?.thumbnail ? <img src={image.thumbnail} alt="" className="size-full object-cover" /> : <ImageIcon className="size-5 text-ink-muted" aria-hidden />}
                </div>
                <div className="grid min-w-0 flex-1 gap-1">
                    <span className="truncate text-copy-14 text-ink" data-test={`image-${id}-name`}>
                        {image === null ? t('content::admin_shared.image.none') : image.name}
                    </span>
                    {image === null ? null : image.status === 'PENDING' ? (
                        <span className="text-copy-13 text-ink-muted">{t('content::admin_shared.image.pending')}</span>
                    ) : image.status === 'FAILED' ? (
                        <span className="text-copy-13 text-bad">{t('content::admin_shared.image.failed')}</span>
                    ) : null}
                </div>
                <div className="flex gap-2">
                    <Button type="button" variant="outline" size="sm" disabled={percent !== null} onClick={() => picker.current?.click()} data-test={`image-${id}-choose`}>
                        {image === null ? t('content::admin_shared.image.choose') : t('content::admin_shared.image.replace')}
                    </Button>
                    {image === null ? null : (
                        <Button type="button" variant="ghost" size="sm" onClick={() => onChange(null)} data-test={`image-${id}-remove`}>
                            {t('content::admin_shared.image.remove')}
                        </Button>
                    )}
                </div>
                <input
                    ref={picker}
                    id={id}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="sr-only"
                    aria-describedby={`${id}-note`}
                    onChange={(event) => {
                        const file = event.target.files?.[0];
                        event.target.value = '';

                        if (file !== undefined) {
                            upload(file);
                        }
                    }}
                    data-test={`image-${id}-file`}
                />
            </div>
            {percent === null ? null : (
                <div className="grid gap-1">
                    <Suspense fallback={null}>
                        <Progress value={percent} aria-labelledby={`${id}-progress`} />
                    </Suspense>
                    <span id={`${id}-progress`} className="tw-figure text-copy-13 text-ink-muted">
                        {t('content::admin_shared.image.uploading', { percent })}
                    </span>
                </div>
            )}
            {note ? <FieldDescription id={`${id}-note`}>{note}</FieldDescription> : null}
            {small && image !== null ? (
                <Note variant="warning" size="small" data-test={`image-${id}-small`}>
                    {t('content::admin_shared.image.small', { width: image.width ?? 0, height: image.height ?? 0 })}
                </Note>
            ) : null}
            {shown ? (
                <FieldError role="alert" data-test={`image-${id}-error`}>
                    {shown}
                </FieldError>
            ) : null}
        </Field>
    );
}
