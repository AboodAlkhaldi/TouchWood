import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { VideoTile, type ShopVideo } from '@/components/shop/VideoTile';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The shop's photo viewer (flags-2026-11.md S4.3): a shadcn `Dialog` (its Close button is ours, in the page's language, above the photo) showing one photo at its largest ready size, with
| Previous and Next - and the arrow keys - over the list it was opened on. The product page has no viewer of its own
| (its gallery is a swipe and thumbnails), so this is the shop's first; a project's gallery opens every photo here, and
| its YouTube videos after them (S4.4): a video opened from its tile plays at once; one reached with Previous or Next
| shows its tile, and YouTube's player loads only when Play is pressed. A video left stops: its player goes. The
| arrows follow the reading direction.
*/

export type GalleryItem = { photo: PhotoSizes; video?: undefined } | { video: ShopVideo; photo?: undefined };

type Props = {
    /** The photos of the gallery, then its videos, in order. */
    items: GalleryItem[];
    /** The one open, or null while closed. */
    index: number | null;
    onIndex: (index: number | null) => void;
    /** What the gallery is: named for people using a screen reader. */
    title: string;
    /** Opened by pressing a video's tile: that video plays at once. */
    play?: boolean;
};

export function PhotoViewer({ items, index, onIndex, title, play = false }: Props) {
    const t = useTranslator();
    const { direction } = usePage<SharedProps>().props;
    // The video opened by its tile plays at once; once the viewer moves, a video waits for its Play.
    const [auto, setAuto] = useState(play);
    const open = index !== null && items[index] !== undefined;
    const last = items.length - 1;
    const go = (step: number) => {
        setAuto(false);
        onIndex(index === null ? null : (index + step + items.length) % items.length);
    };
    const item = index === null ? undefined : items[index];

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? undefined : onIndex(null))}>
            <DialogContent
                className="max-w-4xl gap-3 p-3 sm:max-w-4xl"
                showCloseButton={false}
                data-test="photo-viewer"
                onKeyDown={(event) => {
                    // Left and right as the page reads: its direction decides which is Next.
                    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                        event.preventDefault();
                        go((event.key === 'ArrowRight') !== (direction === 'rtl') ? 1 : -1);
                    }
                }}
            >
                <DialogTitle className="sr-only">{title}</DialogTitle>
                <DialogDescription className="sr-only">{t('content::shop.project.viewer_count', { number: (index ?? 0) + 1, total: items.length })}</DialogDescription>
                {/* Our own Close, in the page's language and above the photo rather than over it, so it reads on any picture. */}
                <div className="flex justify-end">
                    <DialogClose asChild>
                        <Button type="button" variant="ghost" size="icon" aria-label={t('ui.close')} data-test="viewer-close">
                            <X aria-hidden="true" />
                        </Button>
                    </DialogClose>
                </div>
                {item === undefined || index === null ? null : item.video !== undefined ? (
                    <VideoTile key={item.video.id} video={item.video} label={t('content::shop.project.video_play')} autoPlay={auto} className="relative aspect-video max-h-[75vh] w-full overflow-hidden rounded-md" test="viewer-video" />
                ) : (
                    <ShopPhoto photo={item.photo} size="zoom" alt="" eager className="max-h-[75vh] w-full rounded-md object-contain" />
                )}
                {last > 0 && index !== null ? (
                    <div className="flex items-center justify-between gap-3">
                        <Button type="button" variant="outline" size="icon" aria-label={t('content::shop.project.viewer_previous')} onClick={() => go(-1)} data-test="viewer-previous">
                            <ChevronLeft aria-hidden="true" className="rtl:rotate-180" />
                        </Button>
                        <span className="tw-figure text-label-13 text-ink-muted" data-test="viewer-count">
                            {t('content::shop.project.viewer_count', { number: index + 1, total: items.length })}
                        </span>
                        <Button type="button" variant="outline" size="icon" aria-label={t('content::shop.project.viewer_next')} onClick={() => go(1)} data-test="viewer-next">
                            <ChevronRight aria-hidden="true" className="rtl:rotate-180" />
                        </Button>
                    </div>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
