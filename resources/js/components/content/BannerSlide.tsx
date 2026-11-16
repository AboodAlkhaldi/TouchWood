import { ContentLink } from '@/components/content/ContentLink';
import type { PhotoSizes } from '@/components/shop/ShopPhoto';
import { Button } from '@/components/ui/button';

/*
| One of the home's banners (shop-design.md §3.1): its wide picture and its phone picture, each fetched only at its own
| width, and its words over them on a dark band - real text, read and translated. Drawn by the server as the first
| banner, and by the carousel (HomeBanners, loaded after the page shows) for each.
*/

export type Banner = {
    id: string;
    link: string | null;
    alt: string | null;
    image: PhotoSizes | [];
    phone: PhotoSizes | [];
    title: string | null;
    line: string | null;
    button: string | null;
};

/** An image no width asks for: the `<source>` that keeps a picture's file from the other width. */
const NOTHING = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

/** A picture drawn at one width only: at the other, its `<source>` is an empty image, so its file is never fetched. */
export function WidthPicture({ photo, alt, wide, first, className }: { photo: PhotoSizes; alt: string; wide: boolean; first: boolean; className: string }) {
    const formats = photo.detail ?? photo.zoom ?? photo.card ?? {};
    const src = formats.jpg ?? formats.webp ?? formats.avif;
    const media = wide ? '(max-width: 640px)' : '(min-width: 641px)';

    if (src === undefined) {
        return null;
    }

    return (
        <picture>
            <source media={media} srcSet={NOTHING} />
            {formats.avif ? <source type="image/avif" srcSet={formats.avif} /> : null}
            {formats.webp ? <source type="image/webp" srcSet={formats.webp} /> : null}
            <img src={src} alt={alt} loading={first ? 'eager' : 'lazy'} decoding="async" {...(first ? { fetchPriority: 'high' as const } : {})} className={className} />
        </picture>
    );
}

export function BannerSlide({ banner, first, store, locale }: { banner: Banner; first: boolean; store: string; locale: string }) {
    return (
        <div className="relative overflow-hidden sm:rounded-xl">
            <ContentLink link={banner.link} store={store} locale={locale} className="block">
                {banner.image.length === 0 ? null : <WidthPicture photo={banner.image as PhotoSizes} alt={banner.alt ?? ''} wide first={first} className="aspect-[3/1] w-full object-cover" />}
                {banner.phone.length === 0 ? null : <WidthPicture photo={banner.phone as PhotoSizes} alt={banner.alt ?? ''} wide={false} first={first} className="aspect-[2/1] w-full object-cover" />}
            </ContentLink>
            {banner.title || banner.line || banner.button ? (
                <div className="absolute inset-x-3 bottom-3 grid max-w-md gap-1 rounded-lg bg-sidebar/85 p-3 text-sidebar-ink sm:inset-x-6 sm:bottom-6 sm:gap-2 sm:p-5" data-test="home-banner-words">
                    {banner.title ? <span className="text-heading-16 sm:text-heading-24">{banner.title}</span> : null}
                    {banner.line ? <span className="text-copy-13 text-sidebar-ink-muted sm:text-copy-14">{banner.line}</span> : null}
                    {banner.button && banner.link ? (
                        <Button asChild size="sm" className="mt-1 justify-self-start bg-accent text-ink-on-brand hover:bg-accent/90">
                            <ContentLink link={banner.link} store={store} locale={locale}>{banner.button}</ContentLink>
                        </Button>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
