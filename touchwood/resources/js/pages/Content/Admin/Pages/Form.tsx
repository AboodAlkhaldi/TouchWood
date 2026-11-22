import { DocumentForm } from '@/components/content/document-form';
import { useTranslator } from '@/lib/t';
import type { DocumentFormPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W7 · New Page and a page's own page (content.md §4.4, §1.11): title, address, body and SEO per language; Publish Page or
| Save Draft; once published, Save Changes; ⋯ Unpublish Page… or Delete Page… (a draft). The form is the three kinds' own
| (document-form.tsx).
*/

export default function Form(page: DocumentFormPageData) {
    const t = useTranslator();

    return (
        <DocumentForm
            page={page}
            words={{
                list: t('content::admin_pages.title'),
                new: t('content::admin_pages.new'),
                item: t('content::admin_pages.item'),
                publish: t('content::admin_pages.publish'),
                unpublishMenu: t('content::admin_pages.unpublish.menu'),
                unpublishTitle: t('content::admin_pages.unpublish.title'),
                unpublishBody: t('content::admin_pages.unpublish.body'),
                unpublishConfirm: t('content::admin_pages.unpublish.confirm'),
                deleteMenu: t('content::admin_pages.delete.menu'),
                deleteTitle: t('content::admin_pages.delete.title'),
                deleteBody: t('content::admin_pages.delete.body'),
                deleteConfirm: t('content::admin_pages.delete.confirm'),
            }}
        />
    );
}
