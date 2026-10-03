import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button, Modal, ModalCancel, Note } from '@/components/geist';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F10 - closing the account (frontend.md §3.6, access.md §1.10), confirmed in Geist's Modal (1.10).
|
| It says plainly what happens, before anything is confirmed: the account is locked at once,
| everything in it is anonymized fourteen days later, and signing in during those days cancels it -
| which is the only way back, because **confirming signs them out everywhere at once** (amendment
| 45). That is also why there is no way to cancel the closing anywhere in the account: they cannot
| reach one. The tab says the first part, beside a red button that only opens the Modal; the Modal,
| at the moment of confirming, says the second.
|
| The number of days is the server's, never written here.
|
| Two steps, and the second asks for the password. Not because the password is a formality - the
| handler checks it, and a wrong one counts towards the sign-in lockout - but because a button that
| ends an account on one press is a button somebody presses by accident. The password is this
| Modal's typed gate: Geist asks for a typed confirmation before an action this serious, and the
| password is a stronger one than the account's name, so nothing else is asked to be typed. It has
| the focus when the Modal opens, as a typed gate does; and as on every destructive Modal, a click
| outside does not close it.
*/

type Props = {
    account: CustomerAccountPage;
};

/** Ties the Modal's confirm button, which sits in its footer, to the form in its body. */
const FORM_ID = 'close-account-form';

export function CloseTab({ account }: Props) {
    const t = useTranslator();
    const link = useLink();
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ current_password: '' });

    const dismiss = () => {
        setConfirming(false);
        form.reset();
    };

    return (
        <div className="grid gap-5">
            <div className="grid gap-3">
                <h2 className="text-heading-16 text-ink">{t('access::account.close_account')}</h2>

                <Note variant="warning">
                    {t('access::account.close_account_body', { count: account.deletionDays })}
                </Note>
            </div>

            {/* Its own words, ending in "…" because it opens a dialog (frontend.md 1.10). */}
            <Button type="error" className="w-fit" data-test="close-account" onClick={() => setConfirming(true)}>
                {t('access::account.close_account_open')}
            </Button>

            <Modal
                open={confirming}
                onOpenChange={(open) => (open ? setConfirming(true) : form.processing ? undefined : dismiss())}
                title={t('access::account.close_account')}
                description={t('access::account.close_account_signs_out')}
                destructive
                actions={
                    <>
                        <ModalCancel onClick={dismiss} disabled={form.processing} />
                        <Button
                            type="error"
                            typeName="submit"
                            form={FORM_ID}
                            loading={form.processing}
                            data-test="confirm-close-account"
                        >
                            {t('access::account.close_account_confirm')}
                        </Button>
                    </>
                }
            >
                <form
                    id={FORM_ID}
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(link('storefront.account.close'));
                    }}
                    className="grid gap-4"
                >
                    <FormError />

                    <PasswordInput
                        id="close_password"
                        name="current_password"
                        label={t('access::account.current_password')}
                        error={form.errors.current_password}
                        autoComplete="current-password"
                        required
                        autoFocus
                        value={form.data.current_password}
                        onChange={(event) => form.setData('current_password', event.target.value)}
                    />
                </form>
            </Modal>
        </div>
    );
}
