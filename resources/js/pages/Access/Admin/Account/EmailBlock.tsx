import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Input, Modal, ModalCancel, Note } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B1's email (frontend.md §3.2), in Geist (1.10).
|
| Read only for everybody but a Super Admin, who gets "Change email" instead of "Ask an admin to
| change it". Nothing changes when the form is sent: a link goes to the **new** address and the
| email changes only when somebody opens it, so an address typed wrong changes nothing at all
| (Access amendment 17). Until then the screen says the change is pending, in a Note beside the
| address, which is the difference between "it did not work" and "it is waiting for you".
|
| Whether this person may do it is the server's answer, carried as `canChangeEmail`. It is not the
| protection - the handler checks it again, and refuses anyone else.
|
| The new address is asked for in Geist's Modal: Cancel, then the one button that sends the link.
| That button sits in the modal's footer, outside the form, and is tied to it by the form's id, so
| Enter in the field still sends it.
*/

type Props = {
    account: AccountPage;
};

export function EmailBlock({ account }: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const form = useForm({ email: '' });

    return (
        <section className="material-base grid gap-3 p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <h2 className="text-heading-16 text-ink">{t('access::account.email')}</h2>
                    <p className="text-copy-14 text-ink-muted" dir="ltr">
                        {account.email}
                    </p>
                </div>

                {account.canChangeEmail ? (
                    <Button
                        type="secondary"
                        size="small"
                        onClick={() => setOpen(true)}
                        data-test="open-email-dialog"
                    >
                        {t('access::account.change_email')}
                    </Button>
                ) : (
                    <p className="text-copy-13 text-ink-muted">{t('access::account.email_locked')}</p>
                )}
            </div>

            {account.pendingEmail === null ? null : (
                <Note size="small">{t('access::account.email_pending', { email: account.pendingEmail })}</Note>
            )}

            <Modal
                open={open}
                onOpenChange={setOpen}
                title={t('access::account.email_dialog_title')}
                description={t('access::account.email_dialog_body')}
                actions={
                    <>
                        <ModalCancel onClick={() => setOpen(false)} />
                        <Button
                            typeName="submit"
                            form="email-change-form"
                            loading={form.processing}
                            data-test="send-email-link"
                        >
                            {t('access::account.send_link')}
                        </Button>
                    </>
                }
            >
                <form
                    id="email-change-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/admin/account/email', {
                            preserveScroll: true,
                            preserveState: true,
                            // Closed only when it worked. A refused address leaves the modal
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
                    <Input
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
                </form>
            </Modal>
        </section>
    );
}
