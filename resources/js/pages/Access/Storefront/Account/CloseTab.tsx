import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button } from '@/components/ui/button';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F10 - closing the account (frontend.md §3.6, access.md §1.10).
|
| It says plainly what happens, before anything is confirmed: the account is locked at once,
| everything in it is anonymized fourteen days later, and signing in during those days cancels it -
| which is the only way back, because **confirming signs them out everywhere at once** (amendment
| 45). That is also why there is no cancel button anywhere in the account: they cannot reach one.
|
| The number of days is the server's, never written here.
|
| Two steps, and the second asks for the password. Not because the password is a formality - the
| handler checks it, and a wrong one counts towards the sign-in lockout - but because a button that
| ends an account on one press is a button somebody presses by accident.
*/

type Props = {
    account: CustomerAccountPage;
};

export function CloseTab({ account }: Props) {
    const t = useTranslator();
    const link = useLink();
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ current_password: '' });

    return (
        <div className="grid gap-5">
            <div className="grid gap-2 rounded-lg border border-bad/30 bg-bad-soft p-4">
                <p className="text-sm font-medium text-bad">{t('access::account.close_account')}</p>

                <p className="text-sm text-ink-muted">
                    {t('access::account.close_account_body', { count: account.deletionDays })}
                </p>

                <p className="text-sm text-ink-muted">
                    {t('access::account.close_account_signs_out')}
                </p>
            </div>

            {confirming ? (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(link('storefront.account.close'));
                    }}
                    className="grid gap-4"
                >
                    <FormError />

                    <Field
                        id="close_password"
                        label={t('access::account.current_password')}
                        error={form.errors.current_password}
                    >
                        <PasswordInput
                            id="close_password"
                            name="current_password"
                            autoComplete="current-password"
                            required
                            autoFocus
                            value={form.data.current_password}
                            onChange={(event) =>
                                form.setData('current_password', event.target.value)
                            }
                        />
                    </Field>

                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="confirm-close-account"
                        >
                            {t('access::account.close_account_confirm')}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                setConfirming(false);
                                form.reset();
                            }}
                        >
                            {t('access::account.cancel')}
                        </Button>
                    </div>
                </form>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    className="w-fit"
                    data-test="close-account"
                    onClick={() => setConfirming(true)}
                >
                    {t('access::account.close_account')}
                </Button>
            )}
        </div>
    );
}
