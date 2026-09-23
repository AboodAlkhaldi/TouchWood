import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Dialog } from '@/components/Dialog';
import { Field } from '@/components/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B1's email (frontend.md §3.2).
|
| Read only for everybody but a Super Admin, who gets "Change email" instead of "Ask an admin to
| change it". Nothing changes when the form is sent: a link goes to the **new** address and the
| email changes only when somebody opens it, so an address typed wrong changes nothing at all
| (Access amendment 17). Until then the screen says the change is pending, which is the difference
| between "it did not work" and "it is waiting for you".
|
| Whether this person may do it is the server's answer, carried as `canChangeEmail`. It is not the
| protection - the handler checks it again, and refuses anyone else.
*/

type Props = {
    account: AccountPage;
};

export function EmailBlock({ account }: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const form = useForm({ email: '' });

    return (
        <div className="grid gap-3 rounded-lg border border-line bg-surface p-6 shadow-card">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <p className="text-sm font-medium text-ink">{t('access::account.email')}</p>
                    <p className="text-sm text-ink-muted" dir="ltr">
                        {account.email}
                    </p>
                </div>

                {account.canChangeEmail ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setOpen(true)}
                        data-test="open-email-dialog"
                    >
                        {t('access::account.change_email')}
                    </Button>
                ) : (
                    <p className="text-xs text-ink-muted">{t('access::account.email_locked')}</p>
                )}
            </div>

            {account.pendingEmail === null ? null : (
                <p className="rounded-md border border-info/30 bg-info-soft px-4 py-3 text-xs text-info">
                    {t('access::account.email_pending', { email: account.pendingEmail })}
                </p>
            )}

            <Dialog
                open={open}
                onOpenChange={setOpen}
                title={t('access::account.email_dialog_title')}
                description={t('access::account.email_dialog_body')}
            >
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
                    className="grid gap-4"
                >
                    <Field
                        id="new_email"
                        label={t('access::account.new_email')}
                        error={form.errors.email}
                    >
                        <Input
                            id="new_email"
                            name="email"
                            type="email"
                            required
                            autoFocus
                            dir="ltr"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                    </Field>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={form.processing} data-test="send-email-link">
                            {t('access::account.send_link')}
                        </Button>

                        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                            {t('access::account.cancel')}
                        </Button>
                    </div>
                </form>
            </Dialog>
        </div>
    );
}
