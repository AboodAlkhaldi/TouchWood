import { type RefObject, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { useTranslator } from '@/lib/t';
import { AttributeFields, attributeForm, useAttributeChecks } from './AttributeDialog';

/*
| Add an attribute, from the list (catalog.md §4.4 S3). In its own file so that the attribute's own page, which shows the
| same fields with no dialog, doesn't load a dialog with them (frontend.md §5, the page budget).
*/

export function AddAttributeDialog({
    open,
    onOpenChange,
    returnFocusTo,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm(attributeForm(null));
    const checks = useAttributeChecks(form);

    useEffect(() => {
        if (open) {
            form.setDefaults(attributeForm(null));
            form.reset();
            form.clearErrors();
        }
    }, [open]);

    function submit() {
        checks.submit(() => form.post('/admin/attributes', { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_attributes.add')}
            description={t('catalog::admin_attributes.add_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-attribute">
                    {t('catalog::admin_attributes.add')}
                </ActionButton>
            }
        >
            <AttributeFields form={form} checks={checks} attribute={null} />
        </PanelDialog>
    );
}
