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

/** Below Tailwind's `sm` (640 px) a phone, from it the wide picture: the same line as the layout's. */
const PHONE = '(max-width: 639.98px)';

/**
 * A banner's one picture: the phone's file below 640 px, the wide one from it - one box, one description, and each
 * file fetched only at its own width (the browser takes the first `<source>` whose media matches).
 */
export function BannerPicture({ wide, phone, alt, first }: { wide: PhotoSizes | []; phone: PhotoSizes | []; alt: string; first: boolean }) {
    const formatsOf = (photo: PhotoSizes | []) => (Array.isArray(photo) ? {} : (photo.detail ?? photo.zoom ?? photo.card ?? {}));
    const big = formatsOf(wide.length === 0 ? phone : wide);
    const small = formatsOf(phone.length === 0 ? wide : phone);
    const src = big.jpg ?? big.webp ?? big.avif;

    if (src === undefined) {
        return null;
    }

    return (
        <picture>
            {small.avif ? <source media={PHONE} type="image/avif" srcSet={small.avif} /> : null}
            {small.webp ? <source media={PHONE} type="image/webp" srcSet={small.webp} /> : null}
            {(small.jpg ?? small.webp) ? <source media={PHONE} srcSet={small.jpg ?? small.webp} /> : null}
            {big.avif ? <source type="image/avif" srcSet={big.avif} /> : null}
            {big.webp ? <source type="image/webp" srcSet={big.webp} /> : null}
            <img
                src={src}
                alt={alt}
                loading={first ? 'eager' : 'lazy'}
                decoding="async"
                {...(first ? { fetchPriority: 'high' as const } : {})}
                className="aspect-[2/1] w-full object-cover sm:aspect-[3/1]"
                data-test="banner-picture"
            />
        </picture>
    );
}

export function BannerSlide({ banner, first, store, locale }: { banner: Banner; first: boolean; store: string; locale: string }) {
    return (
        <div className="relative overflow-hidden sm:rounded-xl">
            <ContentLink link={banner.link} store={store} locale={locale} className="block">
                <BannerPicture wide={banner.image} phone={banner.phone} alt={banner.alt ?? ''} first={first} />
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
