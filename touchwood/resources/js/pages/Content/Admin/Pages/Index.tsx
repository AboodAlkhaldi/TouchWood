import { DocumentList } from '@/components/content/document-list';
import { useTranslator } from '@/lib/t';
import type { DocumentsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/* W7 · Pages (content.md §4.4, §1.11): tabs Published and Draft, each row opening its page. */

export default function Index(page: DocumentsPageData) {
    const t = useTranslator();

    return (
        <DocumentList
            page={page}
            base="/admin/content/pages"
            form="/admin/content/pages"
            words={{ title: t('content::admin_pages.title'), subtitle: t('content::admin_pages.subtitle'), new: t('content::admin_pages.new'), empty: t('content::admin_pages.empty'), emptyBody: t('content::admin_pages.empty_body') }}
        />
    );
}
