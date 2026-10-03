import { useEffect, useRef, useState, type ReactNode } from 'react';
import { AlertTriangle, X } from 'lucide-react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { useTranslator } from '@/lib/t';
import { Button } from './Button';
import { cx } from './cx';
import { Note } from './Feedback';

/*
| Geist's Modal, Destructive Action Modal and Sheet (frontend.md 1.10).
|
| Modal: a decision that must block the page. Its title is a Title Case statement, never a question
| ("Delete Address", not "Delete this address?"); its body one to three sentences, the consequence
| first; its primary button repeats the title's verb and noun, and the success toast answers it
| ("Address deleted"). Cancel stays "Cancel". On a destructive modal focus starts on Cancel, so Enter
| never destroys anything by accident.
|
| Destructive Action Modal: the same, with friction - the confirm button wakes only when the person
| has typed the name of the thing (or a lowercase phrase when there is no name). For actions serious
| enough to pause on: closing an account, removing a role, rotating a key. Routine confirmations
| use a plain Modal.
|
| Sheet: persistent side context the page stays usable beside; never a destructive confirmation.
|
| Radix traps focus inside, closes on Escape and returns focus to the trigger. Centred with auto
| margins, which have no direction, so an Arabic page cannot push it off to one side.
*/

type ModalProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    /** Sentence case, the consequence first. */
    description?: ReactNode;
    children?: ReactNode;
    /** The footer: Cancel, then the primary action. */
    actions?: ReactNode;
    /** Start on Cancel, and do not close on an outside click. */
    destructive?: boolean;
    size?: 'small' | 'medium' | 'large';
};

const WIDTH = { small: 'max-w-sm', medium: 'max-w-md', large: 'max-w-xl' };

/**
 * Focus goes back to whatever opened the dialog (Geist; frontend.md §6). Radix does that through
 * its own Trigger, which these controlled dialogs never render, so it fell to the page's start
 * (found in the Geist move, 2026-10-02).
 *
 * The opener is read while the opening is being rendered - before a field inside takes focus with
 * autoFocus. Radix's own "about to focus" hook is not enough: it is never called when something
 * inside already has focus, which is exactly the typed-confirmation dialog (the review of the move).
 * Reading `document` here is safe on the server too: a dialog renders closed there.
 */
function useOpener(open: boolean) {
    const opener = useRef<HTMLElement | null>(null);
    const wasOpen = useRef(false);

    if (open && !wasOpen.current && typeof document !== 'undefined') {
        opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }
    wasOpen.current = open;

    return {
        giveBack: (event: Event) => {
            if (opener.current !== null && opener.current.isConnected) {
                event.preventDefault();
                opener.current.focus();
            }
        },
    };
}

export function Modal({ open, onOpenChange, title, description, children, actions, destructive = false, size = 'medium' }: ModalProps) {
    const t = useTranslator();
    const body = useRef<HTMLDivElement>(null);
    const opener = useOpener(open);

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-ink/50" />
                <DialogPrimitive.Content
                    ref={body}
                    onCloseAutoFocus={opener.giveBack}
                    onOpenAutoFocus={(event) => {
                        if (!destructive) {
                            return;
                        }
                        const cancel = body.current?.querySelector<HTMLElement>('[data-modal-cancel]');
                        if (cancel) {
                            event.preventDefault();
                            cancel.focus();
                        }
                    }}
                    onInteractOutside={(event) => (destructive ? event.preventDefault() : undefined)}
                    className={cx(
                        'material-modal fixed inset-0 z-50 m-auto flex h-fit max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] flex-col overflow-hidden',
                        WIDTH[size],
                    )}
                >
                    <div className="grid gap-2 overflow-y-auto p-6">
                        <div className="flex items-start justify-between gap-4">
                            <DialogPrimitive.Title className="text-heading-20 text-ink">{title}</DialogPrimitive.Title>
                            <DialogPrimitive.Close
                                aria-label={t('ui.close')}
                                className="-m-1 rounded-[var(--tw-radius-sm)] p-1 text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                            >
                                <X className="size-4" />
                            </DialogPrimitive.Close>
                        </div>
                        {description === undefined ? null : (
                            <DialogPrimitive.Description className="text-copy-14 text-ink-muted">{description}</DialogPrimitive.Description>
                        )}
                        {children === undefined ? null : <div className="mt-2">{children}</div>}
                    </div>
                    {actions === undefined ? null : (
                        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface-sunken px-6 py-4">{actions}</div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

/** The literal Cancel every modal ends with; marked so a destructive modal starts on it. */
export function ModalCancel({ onClick, label, disabled }: { onClick: () => void; label?: string; disabled?: boolean }) {
    const t = useTranslator();

    return (
        <Button type="secondary" onClick={onClick} disabled={disabled} data-modal-cancel="" data-test="modal-cancel">
            {label ?? t('ui.cancel')}
        </Button>
    );
}

type DestructiveProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Title Case Verb + Noun ("Close Account"); the confirm button says the same. */
    title: string;
    description: ReactNode;
    /** What must be typed: the thing's own name, or a lowercase phrase when it has none. */
    verificationPhrase: string;
    /** "account email", "role name" - read as "To confirm, type the role name "Managers"". */
    verificationLabel?: string;
    /** Only for what cannot be undone: "Closing the account cannot be undone." */
    irreversibleDescription?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    loading?: boolean;
    /** The refusal, in the system's voice ("Couldn't close the account. Try again."). */
    error?: string;
    onConfirm: () => void;
};

export function DestructiveActionModal({
    open,
    onOpenChange,
    title,
    description,
    verificationPhrase,
    verificationLabel,
    irreversibleDescription,
    confirmLabel,
    cancelLabel,
    loading = false,
    error,
    onConfirm,
}: DestructiveProps) {
    const t = useTranslator();
    const [typed, setTyped] = useState('');
    const matches = typed === verificationPhrase;
    const opener = useOpener(open);

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
        <DialogPrimitive.Root open={open} onOpenChange={(next) => (loading ? undefined : onOpenChange(next))}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-ink/50" />
                <DialogPrimitive.Content
                    onInteractOutside={(event) => event.preventDefault()}
                    onCloseAutoFocus={opener.giveBack}
                    className="material-modal fixed inset-0 z-50 m-auto flex h-fit max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-md flex-col overflow-hidden"
                >
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (matches && !loading) {
                                onConfirm();
                            }
                        }}
                    >
                        <div className="grid gap-3 p-6">
                            <DialogPrimitive.Title className="text-heading-20 text-ink">{title}</DialogPrimitive.Title>
                            <DialogPrimitive.Description asChild>
                                <div className="text-copy-14 text-ink-muted">{description}</div>
                            </DialogPrimitive.Description>
                            {irreversibleDescription === undefined ? null : (
                                <div className="flex items-start gap-2 rounded-[var(--tw-radius)] bg-bad-soft px-3 py-2 text-copy-13 text-bad">
                                    <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                    <span>{irreversibleDescription}</span>
                                </div>
                            )}
                            <label id="destructive-prompt" htmlFor="destructive-verification" className="text-copy-14 text-ink">
                                {prompt}
                            </label>
                            <input
                                id="destructive-verification"
                                autoFocus
                                autoComplete="off"
                                spellCheck={false}
                                value={typed}
                                onChange={(event) => setTyped(event.target.value)}
                                aria-labelledby="destructive-prompt"
                                data-test="destructive-verification"
                                className="h-9 w-full rounded-[var(--tw-radius)] bg-surface px-3 text-copy-14 text-ink shadow-[0_0_0_1px_var(--tw-line-strong)] outline-none focus:shadow-[0_0_0_1px_var(--tw-brand)]"
                            />
                            {error === undefined || error === '' ? null : (
                                <Note variant="error" size="small" alert>
                                    {error}
                                </Note>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface-sunken px-6 py-4">
                            <ModalCancel onClick={() => onOpenChange(false)} label={cancelLabel} disabled={loading} />
                            <Button type="error" typeName="submit" loading={loading} disabled={!matches} data-test="destructive-confirm">
                                {confirmLabel ?? title}
                            </Button>
                        </div>
                    </form>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

type SheetProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Names the thing shown ("Job Details"), not the page's action. */
    title: ReactNode;
    side?: 'start' | 'end';
    children: ReactNode;
};

export function Sheet({ open, onOpenChange, title, side = 'end', children }: SheetProps) {
    const t = useTranslator();
    const opener = useOpener(open);

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange} modal={false}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Content
                    onInteractOutside={(event) => event.preventDefault()}
                    onCloseAutoFocus={opener.giveBack}
                    className={cx(
                        'material-fullscreen fixed inset-y-2 z-40 flex w-[min(28rem,calc(100%-1rem))] flex-col overflow-hidden',
                        side === 'end' ? 'end-2' : 'start-2',
                    )}
                >
                    <div className="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
                        <DialogPrimitive.Title className="text-heading-16 text-ink">{title}</DialogPrimitive.Title>
                        <DialogPrimitive.Close asChild>
                            <Button type="tertiary" size="small" svgOnly aria-label={t('ui.close')}>
                                <X className="size-4" />
                            </Button>
                        </DialogPrimitive.Close>
                    </div>
                    <div className="flex-1 overflow-y-auto p-5">{children}</div>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
