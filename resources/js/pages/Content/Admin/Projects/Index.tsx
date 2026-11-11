import { DocumentList } from '@/components/content/document-list';
import { Note } from '@/components/Note';
import { useTranslator } from '@/lib/t';
import type { DocumentsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W9 · Projects (content.md §4.4, §1.13): as W7, with a note while none is published - the shop's Projects page stays
| hidden until then.
*/

export default function Index(page: DocumentsPageData) {
    const t = useTranslator();

    return (
        <DocumentList
            page={page}
            base="/admin/content/projects"
            form="/admin/content/projects"
            words={{ title: t('content::admin_projects.title'), subtitle: t('content::admin_projects.subtitle'), new: t('content::admin_projects.new'), empty: t('content::admin_projects.empty'), emptyBody: t('content::admin_projects.empty_body') }}
            note={
                (page.counts.PUBLISHED ?? 0) === 0 ? (
                    <Note size="small" data-test="projects-hidden">
                        {t('content::admin_projects.hidden')}
                    </Note>
                ) : null
            }
        />
    );
}
