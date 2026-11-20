import { useState, type RefObject } from 'react';
import { ActionButton } from '@/components/ActionButton';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';

/*
| A policy's Unpublish… and Delete… (the owner's decisions of 2026-11-20: per store, as a save is): the question, a tick
| per store the policy serves that the person holds the job in - all ticked at first - and the stores it serves that are
| not theirs, shown disabled with the reason; under them what the choice will do. The button keeps its reason while no
| store is ticked. Loaded when it is asked: the form carries none of it on first paint (JavaScriptBudgetTest). A named
| export: a default one would count as a page.
*/
export function PolicyStoresConfirm({
    title,
    description,
    label,
    legend,
    stores,
    others,
    notYours,
    pickReason,
    summary,
    busy,
    onConfirm,
    onClose,
    returnFocusTo,
    test,
}: {
    title: string;
    description: string;
    label: string;
    legend: string;
    stores: { id: string; code: string; name: string }[];
    others: string[];
    notYours: string;
    pickReason: string;
    summary: (ticked: string[]) => string | null;
    busy: boolean;
    onConfirm: (ticked: string[]) => void;
    onClose: () => void;
    returnFocusTo: RefObject<HTMLElement | null>;
    test: string;
}) {
    const [ticked, setTicked] = useState<string[]>(() => stores.map((store) => store.id));
    const said = ticked.length === 0 ? null : summary(ticked);

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
                <ActionButton variant="destructive" loading={busy} disabledReason={ticked.length === 0 ? pickReason : undefined} onClick={() => onConfirm(ticked)} data-test={test}>
                    {label}
                </ActionButton>
            }
        >
            <FieldSet className="gap-2">
                <FieldLegend variant="label" className="text-label-14 text-ink">
                    {legend}
                </FieldLegend>
                <div className="grid gap-2" data-test={`${test}-stores`}>
                    {stores.map((store) => (
                        <Field key={store.id} orientation="horizontal" className="w-auto">
                            <Checkbox
                                id={`${test}-${store.code}`}
                                checked={ticked.includes(store.id)}
                                onCheckedChange={(on) => setTicked((was) => (on === true ? [...was, store.id] : was.filter((id) => id !== store.id)))}
                                data-test={`${test}-store-${store.code}`}
                            />
                            <FieldLabel htmlFor={`${test}-${store.code}`} className="font-normal">
                                {store.name}
                            </FieldLabel>
                        </Field>
                    ))}
                    {others.map((name) => (
                        <Field key={name} orientation="horizontal" className="w-auto" data-disabled>
                            <Checkbox id={`${test}-other-${name}`} checked={false} disabled aria-describedby={`${test}-other-${name}-why`} data-test={`${test}-other`} />
                            <div className="grid gap-0.5">
                                <FieldLabel htmlFor={`${test}-other-${name}`} className="font-normal">
                                    {name}
                                </FieldLabel>
                                <FieldDescription id={`${test}-other-${name}-why`}>{notYours}</FieldDescription>
                            </div>
                        </Field>
                    ))}
                </div>
            </FieldSet>
            {said === null ? null : (
                <Note size="small" data-test={`${test}-summary`}>
                    {said}
                </Note>
            )}
        </PanelDialog>
    );
}
