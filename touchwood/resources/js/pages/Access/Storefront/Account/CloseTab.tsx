import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DialogError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F10 - closing the account (frontend.md §3.6, access.md §1.10): a shadcn Card in Geist's Error
| Type form - what happens, then the one action in its footer - confirmed in shadcn's AlertDialog.
|
| It says plainly what happens, before anything is confirmed: the account is locked at once,
| everything in it is anonymized fourteen days later, and signing in during those days cancels it -
| which is the only way back, because **confirming signs them out everywhere at once** (amendment
| 45). That is also why there is no way to cancel the closing anywhere in the account: they cannot
| reach one. The card says the first part, beside a red button that only opens the dialog; the
| dialog, at the moment of confirming, says the second.
|
| The number of days is the server's, never written here.
|
| Two steps, and the second asks for the password. Not because the password is a formality - the
| handler checks it, and a wrong one counts towards the sign-in lockout - but because a button that
| ends an account on one press is a button somebody presses by accident. The password is this
| dialog's typed gate: Geist asks for a typed confirmation before an action this serious, and the
| password is a stronger one than the account's name. It has the focus when the dialog opens, as a
| typed gate does; and since it holds something typed, a click outside does not close it.
*/

type Props = {
    account: CustomerAccountPage;
};

export function CloseTab({ account }: Props) {
    const t = useTranslator();
    const link = useLink();
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ current_password: '' });
    // The password as typed (frontend.md §1.7), with the server's rule: required
    // (CurrentPasswordRequest), never trimmed - the handler checks it as typed.
    const checks = useChecks([
        { id: 'close_password', label: t('access::account.current_password'), value: form.data.current_password, rules: { required: true, keepSpaces: true } },
        // Afresh each time the dialog opens: Cancel empties the box, which is not the person's doing.
    ], confirming);

    const openChange = (open: boolean) => {
        if (form.processing) {
            return;
        }

        setConfirming(open);

        if (!open) {
            form.reset();
            form.clearErrors();
        }
    };

    return (
        <Card className="material-base gap-0 border-0 py-0">
            <CardHeader className="px-6 pt-5 pb-5">
                <CardTitle className="text-heading-20 text-ink">
                    <h2>{t('access::account.shop_tab.close')}</h2>
                </CardTitle>
                <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.close_account_body', { count: account.deletionDays })}</CardDescription>
            </CardHeader>

            <CardFooter className="justify-end border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                <AlertDialog open={confirming} onOpenChange={openChange}>
                    <AlertDialogTrigger asChild>
                        {/* Its own words, ending in "…" because it opens a dialog (frontend.md 1.10). */}
                        <Button type="button" variant="destructive" data-test="close-account">
                            {t('access::account.close_account_open')}
                        </Button>
                    </AlertDialogTrigger>

                    <AlertDialogContent className="material-modal gap-0 overflow-hidden border-0 p-0 data-[size=default]:sm:max-w-md">
                        {/* The form holds the footer too, so Enter in the password sends it. */}
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                checks.submit(() => form.post(link('storefront.account.close')));
                            }}
                        >
                            <div className="grid gap-4 p-6">
                                <AlertDialogHeader>
                                    <AlertDialogTitle className="text-heading-20 text-ink">{t('access::account.close_account')}</AlertDialogTitle>
                                    <AlertDialogDescription className="text-copy-14 text-ink-muted">
                                        {t('access::account.close_account_signs_out')}
                                    </AlertDialogDescription>
                                </AlertDialogHeader>

                                {/* Inside the dialog: the toast sits under its backdrop. */}
                                <DialogError open={confirming} />

                                <PasswordInput
                                    id="close_password"
                                    name="current_password"
                                    label={t('access::account.current_password')}
                                    check={checks.box('close_password', form.errors.current_password)}
                                    autoComplete="current-password"
                                    required
                                    autoFocus
                                    value={form.data.current_password}
                                    onChange={(event) => form.setData('current_password', event.target.value)}
                                />
                            </div>

                            <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                                <AlertDialogCancel disabled={form.processing} data-test="modal-cancel">
                                    {t('ui.cancel')}
                                </AlertDialogCancel>
                                {/* Out of reach until something is typed, and saying why: the password is
                                    the typed gate (owner, 2026-10-04), as Geist's Destructive Action
                                    Modal keeps its button until its phrase is typed. */}
                                <ActionButton
                                    type="submit"
                                    variant="destructive"
                                    loading={form.processing}
                                    disabledReason={form.data.current_password === '' ? t('access::account.close_account_password_first') : checks.reason}
                                    data-test="confirm-close-account"
                                >
                                    {t('access::account.close_account_confirm')}
                                </ActionButton>
                            </AlertDialogFooter>
                        </form>
                    </AlertDialogContent>
                </AlertDialog>
            </CardFooter>
        </Card>
    );
}
