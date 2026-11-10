import { FormError } from '@/components/FormError';
import { StoreFilter } from '@/components/StoreFilter';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { HomePageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { FooterForm } from './footer-form';
import { HomeTabs } from './tabs';

/* W1's Footer tab (content.md §4.4, §1.5): the store's footer, its own page beside the sections' (`?tab=footer`). */
export default function Footer({ stores, storeCode, sections, footer, translationReady }: HomePageData) {
    const t = useTranslator();
    const store = stores.find((each) => each.code === storeCode);

    return (
        <AdminLayout title={t('content::admin_home.title')} subtitle={t('content::admin_home.subtitle')}>
            <div className="grid min-w-0 gap-4">
                <FormError />
                <StoreFilter stores={stores.map((each) => ({ code: each.code, name: each.name, isActive: each.isOn }))} value={storeCode} className="max-w-xs" />
                <HomeTabs tab="footer" storeCode={storeCode} sections={sections.length} />
                <FooterForm key={`${storeCode}-${footer.version}`} storeId={store?.id ?? ''} footer={footer} translationReady={translationReady} />
            </div>
        </AdminLayout>
    );
}
