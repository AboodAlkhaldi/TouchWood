import { usePage } from '@inertiajs/react';
import { ContentLink } from '@/components/content/ContentLink';
import type { ImageSizes, SharedProps } from '@/types/page';

/*
| What goes above a product list (catalog.md amendment 41(a); content.md §1.9, §1.6 `LISTING`, §1.8 part 8), drawn by the
| category's, the brand's and the search's pages from `slots`, by module. Content's: **one smart bar** - full width, one
| line, an ellipsis on a phone - over **one banner**, a `<picture>` that gives a phone its own file and never the
| desktop's (§1.6). Nothing at all when neither is live.
*/

type Banner = { link: string | null; alt: string | null; desktop: ImageSizes; phone: ImageSizes };
type ContentSlot = { smartBar: { text: string; link: string | null } | null; banner: Banner | null };

/** `slots` as the pages carry them: module => what it draws; none arrives as []. */
export type ListingSlotsData = Record<string, unknown> | unknown[] | undefined;

export function ListingSlots({ slots }: { slots: ListingSlotsData }) {
    const { shop, locale } = usePage<SharedProps>().props;
    const content = slots !== undefined && !Array.isArray(slots) ? (slots.content as ContentSlot | undefined) : undefined;

    if (content === undefined || shop === null || shop === undefined) {
        return null;
    }

    const { smartBar, banner } = content;

    return (
        <div className="grid gap-3" data-test="listing-slots">
            {smartBar === null ? null : (
                <p className="truncate rounded-md bg-brand-soft px-3 py-2 text-copy-14 text-ink" data-test="smart-bar">
                    <ContentLink link={smartBar.link} store={shop.code} locale={locale} className="underline-offset-4 hover:underline">
                        {smartBar.text}
                    </ContentLink>
                </p>
            )}
            {banner === null ? null : (
                <div data-test="listing-banner">
                    <ContentLink link={banner.link} store={shop.code} locale={locale}>
                        <BannerPicture banner={banner} />
                    </ContentLink>
                </div>
            )}
        </div>
    );
}

/** A phone's image up to 640 px, the wider one above it, each in the best format the browser draws. */
function BannerPicture({ banner }: { banner: Banner }) {
    const wide = banner.desktop.zoom ?? banner.desktop.detail ?? {};
    const phone = banner.phone.detail ?? banner.phone.card ?? {};
    const fallback = wide.jpg ?? wide.webp ?? phone.jpg ?? phone.webp;

    if (fallback === undefined) {
        return null;
    }

    return (
        <picture>
            {phone.avif ? <source media="(max-width: 640px)" type="image/avif" srcSet={phone.avif} /> : null}
            {phone.webp ? <source media="(max-width: 640px)" type="image/webp" srcSet={phone.webp} /> : null}
            {wide.avif ? <source type="image/avif" srcSet={wide.avif} /> : null}
            {wide.webp ? <source type="image/webp" srcSet={wide.webp} /> : null}
            <img src={fallback} alt={banner.alt ?? ''} decoding="async" className="block aspect-[4/1] w-full rounded-md object-cover sm:aspect-[16/3]" />
        </picture>
    );
}
