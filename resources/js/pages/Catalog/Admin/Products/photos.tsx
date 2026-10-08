import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { Attachment, AttachmentContent, AttachmentMedia, AttachmentTitle } from '@/components/ui/attachment';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { PhotoData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { SortableList } from '../SortableList';

/*
| A product's or a variant's photos (catalog.md §4.4 S9, P5, P8): tiles as the media library's grid
| (shadcn's Attachment), each with its sizes' state - Waiting, Ready, Failed - put in order by dragging,
| removed, or added by uploading here; each change saved at once, the order as shown. The gallery's
| first is the card's photo.
*/

const STATE_TONES: Record<string, Parameters<typeof tone>[0]> = { PENDING: 'gray-subtle', READY: 'green-subtle', FAILED: 'red-subtle' };

export function PhotoGrid({
    photos,
    url,
    max,
    helper,
    first,
    reason,
    testPrefix,
}: {
    photos: PhotoData[];
    /** Where the photos are saved: their ids in order (`media_ids[]`), and new files (`photos[]`). */
    url: string;
    max: number;
    /** What may be uploaded here, in words. */
    helper: string;
    /** What the first photo is called, if it is anything (the card's photo). */
    first?: string;
    /** Why the reader may not change them; undefined for whoever may. */
    reason?: string;
    testPrefix: string;
}) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props;
    const [busy, setBusy] = useState(false);
    const files = useRef<HTMLInputElement>(null);
    const ids = photos.map((photo) => photo.mediaId);

    function save(mediaIds: string[], added: File[] = []) {
        router.post(url, { media_ids: mediaIds, photos: added }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    }

    return (
        <div className="grid gap-3">
            {photos.length > 0 ? (
                reason === undefined ? (
                    <SortableList
                        layout="grid"
                        testPrefix={testPrefix}
                        items={photos.map((photo, index) => {
                            const label = t('catalog::admin_products.photos.photo', { number: index + 1 });

                            return {
                                id: photo.mediaId,
                                label,
                                content: <PhotoTile photo={photo} caption={index === 0 ? first : undefined} />,
                                extra: (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={busy}
                                        aria-label={t('catalog::admin_products.photos.remove_named', { photo: label })}
                                        onClick={() => save(ids.filter((id) => id !== photo.mediaId))}
                                        data-test={`${testPrefix}-remove-${index}`}
                                    >
                                        {t('catalog::admin_products.photos.remove')}
                                    </Button>
                                ),
                            };
                        })}
                        onChange={(order) => save(order)}
                        disabled={busy}
                    />
                ) : (
                    <div role="list" className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {photos.map((photo, index) => (
                            <div key={photo.mediaId} role="listitem">
                                <PhotoTile photo={photo} caption={index === 0 ? first : undefined} />
                            </div>
                        ))}
                    </div>
                )
            ) : null}

            <div className="flex flex-wrap items-center gap-3">
                <Input
                    ref={files}
                    id={`${testPrefix}-files`}
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp"
                    tabIndex={-1}
                    aria-hidden="true"
                    className="sr-only"
                    onChange={(event) => {
                        const chosen = Array.from(event.target.files ?? []);
                        event.target.value = '';

                        if (chosen.length > 0) {
                            save(ids, chosen);
                        }
                    }}
                    data-test={`${testPrefix}-files`}
                />
                <ActionButton
                    type="button"
                    variant="outline"
                    loading={busy}
                    disabledReason={reason ?? (photos.length >= max ? t('catalog::admin_products.photos.max', { max }) : undefined)}
                    onClick={() => files.current?.click()}
                    data-test={`${testPrefix}-add`}
                >
                    {t('catalog::admin_products.photos.add')}
                </ActionButton>
                <FieldDescription className="text-copy-13 text-ink-muted">{helper}</FieldDescription>
            </div>
            {errors.photos ? <FieldError>{errors.photos}</FieldError> : null}
        </div>
    );
}

/** One photo: its thumbnail once its sizes are ready, and their state in words (never colour alone). */
export function PhotoTile({ photo, caption }: { photo: PhotoData; caption?: string }) {
    const t = useTranslator();

    return (
        <Attachment orientation="vertical" state={photo.state === 'FAILED' ? 'error' : 'done'} className="material-base w-full border-0 has-data-[slot=attachment-content]:w-full" data-test="photo">
            <AttachmentMedia variant={photo.thumb === null ? 'icon' : 'image'}>{photo.thumb === null ? <ImageOff aria-hidden="true" /> : <img src={photo.thumb} alt="" loading="lazy" />}</AttachmentMedia>
            <AttachmentContent className="grid gap-1">
                {caption !== undefined ? <AttachmentTitle className="text-label-13 text-ink">{caption}</AttachmentTitle> : null}
                <Badge className={tone(STATE_TONES[photo.state] ?? 'gray-subtle')} data-test="photo-state">
                    {t(`catalog::admin_products.photos.state.${photo.state}`)}
                </Badge>
            </AttachmentContent>
        </Attachment>
    );
}
