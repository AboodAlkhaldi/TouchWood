import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { type Banner, BannerSlide } from '@/components/content/BannerSlide';
import { Carousel, type CarouselApi, CarouselContent, CarouselItem } from '@/components/ui/carousel';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The home's banners turning (shop-design.md §3.1), on shadcn's Carousel: one list for every width. They turn every 5
| seconds and loop; arrows and dots choose one. They stop while the pointer is over them, while a finger is on them or
| the keyboard is in them, and while the tab is hidden (P15) - and never move by themselves for a person who asked their
| device for less motion.
|
| Its own file, loaded once the page shows (frontend.md §5: the carousel's code is not the page's first paint); until
| then the page draws the first banner as it is (Home's `Banners`), so nothing moves when this comes.
*/

/** How long a banner stays before the next (§3.1: 5 seconds, the owner's yes). */
const TURN_MS = 5000;

export default function HomeBanners({ banners, store, locale }: { banners: Banner[]; store: string; locale: string }) {
    const t = useTranslator();
    const { direction } = usePage<SharedProps>().props;
    const [api, setApi] = useState<CarouselApi>();
    const [shown, setShown] = useState(0);
    // Each its own hold, so leaving one never lets them turn while another still holds (P15).
    const [hovered, setHovered] = useState(false);
    const [focused, setFocused] = useState(false);
    const [touched, setTouched] = useState(false);
    const [still, setStill] = useState(false);
    const held = hovered || focused || touched || still;
    const many = banners.length > 1;
    const rtl = direction === 'rtl';

    // Less motion, as the device asks now, and if it changes while the page is open.
    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const read = () => setStill(query.matches);
        read();
        query.addEventListener('change', read);

        return () => query.removeEventListener('change', read);
    }, []);

    useEffect(() => {
        if (!api) {
            return;
        }

        const select = () => setShown(api.selectedScrollSnap());
        select();
        api.on('select', select);

        return () => {
            api.off('select', select);
        };
    }, [api]);

    useEffect(() => {
        if (!api || !many || held) {
            return;
        }

        const turn = window.setInterval(() => {
            if (!document.hidden) {
                api.scrollNext();
            }
        }, TURN_MS);

        return () => window.clearInterval(turn);
    }, [api, many, held]);

    const arrow = 'absolute top-1/2 z-10 hidden size-10 -translate-y-1/2 place-items-center rounded-full bg-surface/90 text-ink shadow-sm hover:bg-surface sm:grid';
    const of = (index: number) => t('content::shop.home.banner_of', { number: figure(locale, index + 1), count: figure(locale, banners.length) });

    return (
        <div
            className="shop-box relative max-sm:px-0!"
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocusCapture={() => setFocused(true)}
            onBlurCapture={(event) => setFocused(event.currentTarget.contains(event.relatedTarget as Node | null))}
            onPointerDown={(event) => event.pointerType !== 'mouse' && setTouched(true)}
            onPointerUp={() => setTouched(false)}
            onPointerCancel={() => setTouched(false)}
            // shadcn's Carousel takes ArrowLeft as "previous" in both directions; in Arabic the left is the next.
            onKeyDownCapture={(event) => {
                if (rtl && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
                    event.preventDefault();
                    event.stopPropagation();
                    void (event.key === 'ArrowLeft' ? api?.scrollNext() : api?.scrollPrev());
                }
            }}
            data-test="home-banners"
            data-turning="true"
        >
            <Carousel setApi={setApi} opts={{ loop: many, direction: rtl ? 'rtl' : 'ltr' }} aria-label={t('content::shop.home.banners')}>
                <CarouselContent className="ms-0">
                    {banners.map((banner, index) => (
                        <CarouselItem key={banner.id} className="ps-0" aria-label={of(index)} data-test="home-banner">
                            <BannerSlide banner={banner} first={index === 0} store={store} locale={locale} />
                        </CarouselItem>
                    ))}
                </CarouselContent>
            </Carousel>
            {many ? (
                <>
                    <button type="button" className={`${arrow} start-14`} onClick={() => api?.scrollPrev()} aria-label={t('content::shop.home.banner_previous')} data-test="banner-previous">
                        <ChevronLeft aria-hidden className="size-5 rtl:rotate-180" />
                    </button>
                    <button type="button" className={`${arrow} end-14`} onClick={() => api?.scrollNext()} aria-label={t('content::shop.home.banner_next')} data-test="banner-next">
                        <ChevronRight aria-hidden className="size-5 rtl:rotate-180" />
                    </button>
                    <div className="mt-3 flex justify-center gap-1.5" data-test="banner-dots">
                        {banners.map((banner, index) => (
                            <button
                                key={banner.id}
                                type="button"
                                onClick={() => api?.scrollTo(index)}
                                aria-label={of(index)}
                                aria-current={index === shown ? 'true' : undefined}
                                className={`h-1.5 rounded-full transition-all ${index === shown ? 'w-6 bg-brand' : 'w-1.5 bg-line-strong'}`}
                            />
                        ))}
                    </div>
                </>
            ) : null}
        </div>
    );
}
