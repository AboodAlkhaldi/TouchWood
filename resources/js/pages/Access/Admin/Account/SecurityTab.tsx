import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B3 - the security tab (frontend.md §3.2).
|
| Changing the password, and nothing else. There is no two-factor switch, because there is no
| two-factor choice: every staff member signs in with a code to their phone, always (§2.7). The tab
| says so rather than leaving a person hunting for a setting that was never there.
|
| There is no list of active sessions either (decided 2026-09-19). A new password already ends every
| other session, which is the thing such a list exists to let you do.
|
| The rule is the setting's, in words, before anyone types - not a message after they have chosen
| something we then refuse.
*/

type Props = {
    account: AccountPage;
};

export function SecurityTab({ account }: Props) {
    const t = useTranslator();
    const [repeated, setRepeated] = useState('');
    const form = useForm({ current_password: '', password: '' });

    // Caught here rather than on the server: the second box exists to catch a typo for the person
    // typing, and it has nothing to do with whether the password is acceptable. What makes a
    // password acceptable is Access's, and Access answers that.
    const differs = repeated !== '' && repeated !== form.data.password;

    return (
        <div className="grid gap-6">
            <form
                onSubmit={(event) => {
                    event.preventDefault();

                    // The button is already disabled while the two differ; this is the same rule
                    // again for a form sent by pressing Enter in a field.
                    if (differs) {
                        return;
                    }

                    form.post('/admin/account/password', {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            setRepeated('');
                        },
                    });
                }}
                className="grid gap-5 rounded-lg border border-line bg-surface p-6 shadow-card"
            >
                <div className="grid gap-1">
                    <h2 className="text-base font-semibold text-ink">
                        {t('access::account.change_password')}
                    </h2>
                    <p className="text-sm text-ink-muted">{t('access::account.password_note')}</p>
                </div>

                <FormError />

                <Field
                    id="current_password"
                    label={t('access::account.current_password')}
                    error={form.errors.current_password}
                >
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        autoComplete="current-password"
                        required
                        value={form.data.current_password}
                        onChange={(event) => form.setData('current_password', event.target.value)}
                    />
                </Field>

                <Field
                    id="password"
                    label={t('access::account.new_password')}
                    hint={t('access::account.password_rule', { count: account.passwordMinLength })}
                    error={form.errors.password}
                >
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        required
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                </Field>

                <Field
                    id="password_repeat"
                    label={t('access::account.confirm_password')}
                    error={differs ? t('access::account.passwords_differ') : undefined}
                >
                    <PasswordInput
                        id="password_repeat"
                        name="password_repeat"
                        autoComplete="new-password"
                        required
                        value={repeated}
                        onChange={(event) => setRepeated(event.target.value)}
                    />
                </Field>

                <Button
                    type="submit"
                    disabled={form.processing || differs}
                    className="w-fit"
                    data-test="save-password"
                >
                    {t('access::account.save')}
                </Button>
            </form>

            <div className="grid gap-1 rounded-lg border border-line bg-surface p-6 shadow-card">
                <h2 className="text-base font-semibold text-ink">
                    {t('access::account.two_factor_title')}
                </h2>
                <p className="text-sm text-ink-muted">{t('access::account.two_factor_body')}</p>
            </div>
        </div>
    );
}
