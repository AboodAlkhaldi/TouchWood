import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { VideosField, type PanelVideo } from '@/components/VideosField';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { youTubeLink } from '@/lib/youtube';
import type { ProductPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| A product's YouTube videos on its Photos tab (flags-2026-11.md S4.4, catalog.md amendment 48): at most 10, the
| product's and not a size's (F22), shown in the shop after the photos. As the photos are, each change is saved at
| once - a video added, moved or removed; a title once its box is left - the list sent whole, each kept video sent back
| as its link. The titles are checked as they are typed (100 characters, CatalogText).
*/

const MAX = 10;
const titleBox = (language: 'ar' | 'en', videoId: string) => `video_title_${language}_${videoId}`;

export function ProductVideos({ page }: { page: ProductPage }) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props;
    const productId = page.product.id;
    const reason = page.mayUpdate ? undefined : t('catalog::admin_products.read_only');
    const saved = (page.videos ?? []).map((video) => ({ videoId: video.videoId, titleAr: video.titleAr ?? '', titleEn: video.titleEn ?? '' }));
    const [list, setList] = useState<PanelVideo[]>(saved);
    // The saved list read again - a save, or another person's - replaces the one shown, set while rendering as React's
    // docs do for state that follows a prop. Nothing is remounted, so a link being typed stays in its box.
    const [read, setRead] = useState(JSON.stringify(saved));

    if (read !== JSON.stringify(saved)) {
        setRead(JSON.stringify(saved));
        setList(saved);
    }
    const [busy, setBusy] = useState(false);
    const checks = useChecks(
        list.flatMap((video) =>
            (['ar', 'en'] as const).map((language) => ({
                id: titleBox(language, video.videoId),
                label: language === 'ar' ? t('catalog::admin_products.videos.title_ar') : t('catalog::admin_products.videos.title_en'),
                value: language === 'ar' ? video.titleAr : video.titleEn,
                rules: { length: { max: 100 } },
            })),
        ),
    );

    const save = (next: PanelVideo[]) => {
        if (JSON.stringify(next) === JSON.stringify(saved)) {
            return;
        }

        checks.submit(() =>
            router.post(
                `/admin/products/${productId}/videos`,
                { videos: next.map((video) => ({ link: youTubeLink(video.videoId), title_ar: video.titleAr, title_en: video.titleEn })) },
                { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
            ),
        );
    };

    return (
        <Card className="material-base border-0">
            <CardContent className="grid gap-4 pt-5">
                <VideosField
                    videos={list}
                    onChange={(next, how) => {
                        setList(next);

                        if (how !== 'typed') {
                            save(next);
                        }
                    }}
                    onTitleLeave={() => save(list)}
                    titleBox={titleBox}
                    words={{
                        legend: t('catalog::admin_products.videos.title'),
                        helper: t('catalog::admin_products.videos.helper'),
                        link: t('catalog::admin_products.videos.link'),
                        linkHelper: t('catalog::admin_products.videos.link_helper'),
                        add: t('catalog::admin_products.videos.add'),
                        titleAr: t('catalog::admin_products.videos.title_ar'),
                        titleEn: t('catalog::admin_products.videos.title_en'),
                        none: t('catalog::admin_products.videos.none'),
                        max: t('catalog::admin_products.videos.max', { max: MAX }),
                        pasteKey: 'catalog::admin_products.videos.paste',
                        onceKey: 'catalog::admin_products.videos.once',
                        video: (number) => t('catalog::admin_products.videos.video', { number }),
                        removeNamed: (name) => t('catalog::admin_products.videos.remove_named', { video: name }),
                    }}
                    checks={checks}
                    errors={errors as Record<string, string | undefined>}
                    max={MAX}
                    busy={busy}
                    reason={reason}
                    testPrefix="video"
                />
            </CardContent>
        </Card>
    );
}
