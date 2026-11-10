import { useState } from 'react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';

/*
| Translate's two questions (content.md §1.3), loaded when one is asked - never on a form's first paint, so the forms
| carry neither's code (JavaScriptBudgetTest; lesson 195). Named exports: a file with a default export counts as a page.
*/

/** "Replace the English text?", before a typed English box is replaced. */
export function ReplaceDialog({ onReplace, onKeep }: { onReplace: () => void; onKeep: () => void }) {
    const t = useTranslator();

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onKeep())}
            title={t('content::admin_shared.translate.replace_title')}
            description={t('content::admin_shared.translate.replace_body')}
            busy={false}
            cancelLabel={t('content::admin_shared.translate.keep')}
            confirm={
                <Button type="button" onClick={onReplace} data-test="replace-text">
                    {t('content::admin_shared.translate.replace')}
                </Button>
            }
        >
            {null}
        </PanelDialog>
    );
}

/** The one required tick before marked English reaches shoppers (§1.3 #3), then the form's own button. */
export function ReadTickDialog({ label, onSend, onClose }: { label: string; onSend: () => void; onClose: () => void }) {
    const t = useTranslator();
    const [ticked, setTicked] = useState(false);

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={t('content::admin_shared.translate.read_title')}
            description={t('content::admin_shared.translate.read_body')}
            busy={false}
            confirm={
                <ActionButton type="button" disabledReason={ticked ? undefined : t('content::admin_shared.translate.read_tick')} onClick={onSend} data-test="read-confirm">
                    {label}
                </ActionButton>
            }
        >
            <Field orientation="horizontal">
                <Checkbox id="read-tick" checked={ticked} onCheckedChange={(on) => setTicked(on === true)} data-test="read-tick" />
                <FieldLabel htmlFor="read-tick" className="font-normal">
                    {t('content::admin_shared.translate.read_tick')}
                </FieldLabel>
            </Field>
        </PanelDialog>
    );
}
