import { useState, type RefObject } from 'react';
import { usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| Schedule Campaign, End Campaign… and Cancel Campaign…'s question (content.md §1.7 #12, W4b: "each asks All Stores or one
| store, naming them: 'Offers stop at once in Saudi Arabia.'"): All Stores or one store of those the step can act on, the
| sentence naming the stores it reaches as they are chosen, and a button repeating the title's verb. Loaded when asked:
| never on first paint (lesson 180). A named export: a default one would count as a page.
*/

export type ScopeStore = { id: string; name: string };

export function ScopeDialog({
    title,
    sentence,
    label,
    stores,
    choose,
    destructive,
    busy,
    onConfirm,
    onClose,
    returnFocusTo,
    test,
}: {
    title: string;
    /** The sentence naming the stores reached, as the language lists them. */
    sentence: (stores: string) => string;
    label: string;
    stores: ScopeStore[];
    /** Whether one store may be chosen: false when the step reaches every store at once. */
    choose: boolean;
    destructive: boolean;
    busy: boolean;
    onConfirm: (storeId: string | null) => void;
    onClose: () => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
    test: string;
}) {
    const t = useTranslator();
    const [scope, setScope] = useState('all');
    const reached = scope === 'all' ? stores : stores.filter((store) => store.id === scope);
    const { locale } = usePage<SharedProps>().props;
    const list = new Intl.ListFormat(intlLocale(locale), { type: 'conjunction' }).format(reached.map((store) => store.name));

    return (
        <PanelDialog
            destructive={destructive}
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            returnFocusTo={returnFocusTo}
            title={title}
            description={sentence(list)}
            busy={busy}
            confirm={
                <ActionButton variant={destructive ? 'destructive' : 'default'} loading={busy} onClick={() => onConfirm(scope === 'all' ? null : scope)} data-test={`${test}-confirm`}>
                    {label}
                </ActionButton>
            }
        >
            {choose && stores.length > 1 ? (
                <RadioGroup value={scope} onValueChange={setScope} className="grid gap-2" aria-label={t('content::admin_campaigns.scope.label')} data-test={`${test}-scope`}>
                    {[{ id: 'all', name: t('content::admin_campaigns.scope.all') }, ...stores].map((store) => (
                        <div key={store.id} className="flex items-center gap-2">
                            <RadioGroupItem id={`${test}-scope-${store.id}`} value={store.id} data-test={`${test}-scope-${store.id}`} />
                            <Label htmlFor={`${test}-scope-${store.id}`} className="font-normal">
                                {store.name}
                            </Label>
                        </div>
                    ))}
                </RadioGroup>
            ) : null}
        </PanelDialog>
    );
}
