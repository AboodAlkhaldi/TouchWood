import { ActionButton } from '@/components/ActionButton';
import { useTranslator } from '@/lib/t';

/*
| Geist's Load More Button on shadcn's Button (frontend.md §1.11): full width, at the foot of a
| keyset list, appending the next page (lib/use-load-more). While the page is on its way it shows
| it is busy and stays where it is, focusable (Geist's Button: loading, never disabled). The word
| is the owner's: "Show More" (frontend.md E7).
*/

export function LoadMoreButton({ loading, onClick }: { loading: boolean; onClick: () => void }) {
    const t = useTranslator();

    return (
        <ActionButton type="button" variant="outline" className="w-full" loading={loading} onClick={onClick} data-test="more">
            {t('ui.load_more')}
        </ActionButton>
    );
}
