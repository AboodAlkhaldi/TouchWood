import { useEffect, useId, useState, type ReactNode, type RefObject } from 'react';
import { AlertTriangle } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';

/*
| Geist's Destructive Action Modal on shadcn's Dialog, Field and Input (frontend.md §1.11), built
| from Geist's own page:
|
| - a typed gate: the confirm button wakes only when the verification phrase is typed exactly - the
|   thing's own name, with `verificationLabel` ("the role name"), or a lowercase phrase when it has
|   none; Enter submits only when the gate is open;
| - the verification input takes focus on open; its prompt labels it;
| - `irreversibleDescription` adds the red band, only for what cannot be undone, ending "cannot be
|   undone."; its icon is hidden, the sentence carries the meaning;
| - `error` is the refusal, said inside, and the dialog stays open to retry;
| - `loading` disables both buttons (the confirm one stays focusable, Geist's Button rule); the
|   caller owns `open` and closes it when the request settles;
| - Cancel, an outside click and Escape all dismiss it, except while loading - which is why this is
|   shadcn's Dialog and not its AlertDialog, which never lets an outside click dismiss;
| - the confirm button repeats the title (Verb + Noun), and the toast answers it 1:1.
|
| `body` holds anything the action needs besides the typed phrase - a replacement role, a reason -
| in the dialog's body, never inside its description, which a screen reader reads whole.
*/

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Title Case Verb + Noun ("Delete Role"); the confirm button says the same. */
    title: string;
    /** Sentence case, the consequence first, naming the thing. Text only. */
    description: ReactNode;
    /** What must be typed: the thing's own name, or a lowercase phrase when it has none. */
    verificationPhrase: string;
    /** "role name" - read as "To confirm, type the role name "Managers"". */
    verificationLabel?: string;
    /** Only for what cannot be undone: "Deleting Managers cannot be undone." */
    irreversibleDescription?: string;
    /** Controls the action needs besides the phrase, shown in the body. */
    body?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    loading?: boolean;
    /** The refusal, in the system's voice ("Couldn't delete the role. Try again."). */
    error?: string;
    onConfirm: () => void;
    /** Where focus goes back when the opener is gone: the ⋯ button of the menu that opened it. */
    returnFocusTo?: RefObject<HTMLElement | null>;
};

export function DestructiveActionDialog({
    open,
    onOpenChange,
    title,
    description,
    verificationPhrase,
    verificationLabel,
    irreversibleDescription,
    body,
    confirmLabel,
    cancelLabel,
    loading = false,
    error,
    onConfirm,
    returnFocusTo,
}: Props) {
    const t = useTranslator();
    const id = useId();
    const [typed, setTyped] = useState('');
    const matches = typed === verificationPhrase;
    const returnFocus = useReturnFocus(open, returnFocusTo);

    // Every opening starts empty: a phrase typed for one thing never confirms the next.
    useEffect(() => {
        if (open) {
            setTyped('');
        }
    }, [open]);

    const prompt =
        verificationLabel === undefined
            ? t('ui.to_confirm', { phrase: verificationPhrase })
            : t('ui.to_confirm_named', { label: verificationLabel, phrase: verificationPhrase });

    return (
        <Dialog open={open} onOpenChange={(next) => (loading ? undefined : onOpenChange(next))}>
            <DialogContent
                showCloseButton={false}
                onCloseAutoFocus={returnFocus}
                className="material-modal gap-0 overflow-hidden border-0 p-0 sm:max-w-md"
                data-test="destructive-dialog"
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (matches && !loading) {
                            onConfirm();
                        }
                    }}
                >
                    <div className="grid gap-4 p-6">
                        <DialogHeader>
                            <DialogTitle className="text-heading-20 text-ink">{title}</DialogTitle>
                            <DialogDescription asChild>
                                <div className="text-copy-14 text-ink-muted">{description}</div>
                            </DialogDescription>
                        </DialogHeader>

                        {body === undefined ? null : <div className="grid gap-4">{body}</div>}

                        <Field>
                            <FieldLabel id={`${id}-prompt`} htmlFor={`${id}-verification`} className="text-copy-14 font-normal text-ink">
                                {prompt}
                            </FieldLabel>
                            <Input
                                id={`${id}-verification`}
                                autoFocus
                                autoComplete="off"
                                spellCheck={false}
                                value={typed}
                                onChange={(event) => setTyped(event.target.value)}
                                aria-labelledby={`${id}-prompt`}
                                data-test="destructive-verification"
                            />
                        </Field>

                        {error === undefined || error === '' ? null : (
                            <Note variant="error" size="small" alert>
                                {error}
                            </Note>
                        )}
                    </div>

                    {irreversibleDescription === undefined ? null : (
                        <div className="flex items-start gap-2 bg-bad-soft px-6 py-3 text-copy-13 text-bad" data-test="destructive-irreversible">
                            <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                            <span>{irreversibleDescription}</span>
                        </div>
                    )}

                    <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                        <Button type="button" variant="outline" disabled={loading} onClick={() => onOpenChange(false)} data-test="modal-cancel">
                            {cancelLabel ?? t('ui.cancel')}
                        </Button>
                        <ActionButton type="submit" variant="destructive" loading={loading} disabled={!matches} data-test="destructive-confirm">
                            {confirmLabel ?? title}
                        </ActionButton>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
