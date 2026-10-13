import { ImageOff } from 'lucide-react';
import { cn } from 'cn';

/*
| A photo in the shop (catalog.md §2.4): one of its ready sizes - thumb, card, detail, zoom - in the
| best format the browser draws, AVIF, then WebP, then JPEG. A product with no ready photo yet shows
| a quiet empty frame, never a broken image.
*/

export type PhotoSizes = Record<string, Record<string, string>>;

type Props = {
    photo: PhotoSizes | null | undefined;
    size: 'thumb' | 'card' | 'detail' | 'zoom';
    alt: string;
    className?: string;
    /** The page's main photo: fetched at once, not when scrolled to. */
    eager?: boolean;
};

export function ShopPhoto({ photo, size, alt, className, eager = false }: Props) {
    const formats = photo?.[size];
    const fallback = formats?.jpg ?? formats?.webp ?? formats?.avif;

    if (formats === undefined || fallback === undefined) {
        return (
            <div
                className={cn('flex items-center justify-center bg-surface-sunken text-ink-muted', className)}
                // A decorative photo (alt "") stays out of the reading order, as its <img> would.
                {...(alt === '' ? { 'aria-hidden': true } : { role: 'img', 'aria-label': alt })}
            >
                <ImageOff aria-hidden="true" className="size-6" />
            </div>
        );
    }

    return (
        <picture>
            {formats.avif ? <source type="image/avif" srcSet={formats.avif} /> : null}
            {formats.webp ? <source type="image/webp" srcSet={formats.webp} /> : null}
            <img src={fallback} alt={alt} loading={eager ? 'eager' : 'lazy'} decoding="async" className={cn('object-cover', className)} />
        </picture>
    );
}
