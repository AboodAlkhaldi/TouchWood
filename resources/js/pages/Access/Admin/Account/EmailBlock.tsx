import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { DialogError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B1's email (frontend.md §3.2), on shadcn's Card and Dialog with Geist's rules (§1.11).
|
| Only a Super Admin changes their own address. Anyone else sees the same Change Work Email… button
| disabled, with "Ask an admin to change it." as its reason (owner, 2026-10-03; Geist: an action
| that cannot be done is shown disabled, with the reason). Nothing changes when the form is sent: a
| link goes to the **new** address and the email changes only when somebody opens it, so an address
| typed wrong changes nothing at all (Access amendment 17). Until then the card says the change is
| pending, in a Note, which is the difference between "it did not work" and "it is waiting for you".
|
| Whether this person may do it is the server's answer, carried as `canChangeEmail`. It is not the
| protection - the handler checks it again, and refuses anyone else.
|
| The new address is asked for in a Dialog whose main button repeats its title (§1.11, "Applied in
| the rebuild"); a refusal is said inside it (DialogError), and it stays open holding what was typed.
*/

type Props = {
    account: AccountPage;
};

export function EmailBlock({ account }: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const form = useForm({ email: '' });
    const returnFocus = useReturnFocus(open);

    return (
        <Card className="material-base gap-3 border-0 py-5">
            <CardHeader className="px-6">
                <CardTitle className="text-heading-20 text-ink">
                    <h2>{t('access::account.email')}</h2>
                </CardTitle>
                <CardDescription className="text-copy-14 text-ink-muted">
                    <bdi dir="ltr">{account.email}</bdi>
                </CardDescription>
                <CardAction>
                    <ActionButton
                        type="button"
                        variant="outline"
                        size="sm"
                        disabledReason={account.canChangeEmail ? undefined : t('access::account.email_locked')}
                        onClick={() => setOpen(true)}
                        data-test="open-email-dialog"
                    >
                        {t('access::account.change_email')}
                    </ActionButton>
                </CardAction>
            </CardHeader>

            {account.pendingEmail === null ? null : (
                <CardContent className="px-6">
                    <Note size="small" label={t('access::account.email_pending_label')}>
                        {t('access::account.email_pending', { email: account.pendingEmail })}
                    </Note>
                </CardContent>
            )}

            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!form.processing) {
                        setOpen(next);
                    }
                }}
            >
                <DialogContent showCloseButton={false} onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 sm:max-w-md">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/admin/account/email', {
                                preserveScroll: true,
                                preserveState: true,
                                // Closed only when it worked. A refused address leaves the dialog
                                // open, holding what was typed, with the refusal beside it - closing
                                // on failure reads as the change having gone through.
                                onSuccess: () => {
                                    form.reset();
                                    setOpen(false);
                                },
                            });
                        }}
                    >
                        <div className="grid gap-4 p-6">
                            <DialogHeader>
                                <DialogTitle className="text-heading-20 text-ink">{t('access::account.email_dialog_title')}</DialogTitle>
                                <DialogDescription className="text-copy-14 text-ink-muted">{t('access::account.email_dialog_body')}</DialogDescription>
                            </DialogHeader>
                            <DialogError open={open} />
                            <TextField
                                id="new_email"
                                name="email"
                                type="email"
                                label={t('access::account.new_email')}
                                error={form.errors.email}
                                required
                                autoFocus
                                dir="ltr"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                        </div>
                        <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                            <Button type="button" variant="outline" disabled={form.processing} onClick={() => setOpen(false)} data-test="modal-cancel">
                                {t('ui.cancel')}
                            </Button>
                            <ActionButton type="submit" loading={form.processing} data-test="send-email-link">
                                {t('access::account.email_dialog_title')}
                            </ActionButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
