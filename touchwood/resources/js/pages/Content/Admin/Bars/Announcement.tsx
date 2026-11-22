import type { BarsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { AnnouncementForm } from './announcement-form';
import { BarsFrame } from './frame';

/* W2 · Bars, the Announcement Bar tab (content.md §4.4, §1.5), under `content.bar.manage`. */
export default function Announcement(page: BarsPageData) {
    const storeId = page.stores.find((each) => each.code === page.storeCode)?.id ?? '';

    return (
        <BarsFrame page={page}>
            <AnnouncementForm key={`${page.storeCode}-${page.bar.version}`} storeId={storeId} zone={page.storeTimezone} bar={page.bar} translationReady={page.translationReady} />
        </BarsFrame>
    );
}
