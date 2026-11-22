import type { RefObject } from 'react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';

/*
| End Now… and Delete…'s question (content.md §4.2: "a button repeating the title's verb"), loaded when it is asked: the
| lists carry none of it on first paint (JavaScriptBudgetTest). A named export: a default one would count as a page.
*/
export function ScheduleConfirm({ title, description, label, busy, onConfirm, onClose, returnFocusTo, test }: { title: string; description: string; label: string; busy: boolean; onConfirm: () => void; onClose: () => void; returnFocusTo: RefObject<HTMLElement | null>; test: string }) {
    return (
        <PanelDialog
            destructive
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            returnFocusTo={returnFocusTo}
            title={title}
            description={description}
            busy={busy}
            confirm={
                <ActionButton variant="destructive" loading={busy} onClick={onConfirm} data-test={test}>
                    {label}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
