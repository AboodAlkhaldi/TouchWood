import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { AttributeData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';

export function DeleteAttributeDialog({ attribute, open, onOpenChange, returnFocusTo }: { attribute: AttributeData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_attributes.delete_title')}
            description={t('catalog::admin_attributes.delete_body', { name: nameIn(locale, attribute.nameAr, attribute.nameEn), count: figure(locale, attribute.values) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/attributes/${attribute.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-attribute"
                >
                    {t('catalog::admin_attributes.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
