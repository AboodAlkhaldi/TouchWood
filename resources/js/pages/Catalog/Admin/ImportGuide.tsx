import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { ImportGuidePage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| The files' guide (docs/modules/catalog-import/README.md), as the panel shows it (catalog.md §4.4
| S10, S11 link it): the repository's own guide - one source, written by the team, raw HTML stripped
| on the server all the same - and its two example files to download. English, as the files' format
| is, so it reads left to right on an Arabic page too.
*/

export default function ImportGuide({ html }: ImportGuidePage) {
    const t = useTranslator();

    return (
        <AdminLayout title={t('catalog::admin_imports.guide_title')}>
            <div className="grid gap-4">
                <nav aria-label={t('catalog::admin_imports.guide_examples')} className="flex flex-wrap gap-4 text-label-14">
                    <a href="/admin/import-guide/products" className="text-ink underline underline-offset-4" data-test="example-products" dir="ltr">
                        products.example.json
                    </a>
                    <a href="/admin/import-guide/store-fill" className="text-ink underline underline-offset-4" data-test="example-store-fill" dir="ltr">
                        store-fill.example.json
                    </a>
                </nav>
                <article
                    dir="ltr"
                    lang="en"
                    data-test="import-guide-text"
                    className="material-base min-w-0 overflow-x-auto p-6 text-start text-copy-14 text-ink [&_a]:underline [&_a]:underline-offset-4 [&_blockquote]:border-s-2 [&_blockquote]:border-line [&_blockquote]:ps-4 [&_blockquote]:text-ink-muted [&_code]:rounded [&_code]:bg-surface-sunken [&_code]:px-1 [&_code]:font-mono [&_code]:text-copy-13 [&_h1]:mb-4 [&_h1]:text-heading-24 [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:text-heading-20 [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:text-heading-16 [&_li]:my-1 [&_ol]:list-decimal [&_ol]:ps-6 [&_p]:my-3 [&_pre]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-surface-sunken [&_pre]:p-3 [&_table]:my-3 [&_table]:w-full [&_td]:border [&_td]:border-line [&_td]:p-2 [&_td]:align-top [&_th]:border [&_th]:border-line [&_th]:p-2 [&_th]:text-start [&_ul]:list-disc [&_ul]:ps-6"
                    // The guide's HTML, made on the server from the repository's own file with raw HTML stripped.
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            </div>
        </AdminLayout>
    );
}
