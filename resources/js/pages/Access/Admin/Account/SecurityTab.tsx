import { useForm } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button, Fieldset } from '@/components/geist';
import { useRepeatedPassword } from '@/lib/passwords';
import { useTranslator } from '@/lib/t';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B3 - the security tab (frontend.md §3.2), in Geist (1.10).
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
|
| The password is a Geist Fieldset that is itself the form, its button in the footer; while the two
| new boxes differ that button is out of reach and says why, in Geist's tooltip.
*/

type Props = {
    account: AccountPage;
};

export function SecurityTab({ account }: Props) {
    const t = useTranslator();
    const form = useForm({ current_password: '', password: '' });
    // Caught here rather than on the server: the second box exists to catch a typo for the person
    // typing, and it has nothing to do with whether the password is acceptable. What makes a
    // password acceptable is Access's, and Access answers that (see lib/passwords).
    const repeat = useRepeatedPassword(form.data.password);
    const differ = t('access::account.passwords_differ');

    return (
        <div className="grid gap-6">
            <Fieldset
                as="form"
                onSubmit={(event) => {
                    event.preventDefault();

                    // The button is already out of reach while the two differ; this is the same
                    // rule again for a form sent by pressing Enter in a field.
                    if (repeat.differs) {
                        return;
                    }

                    form.post('/admin/account/password', {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            repeat.clear();
                        },
                    });
                }}
                title={t('access::account.change_password')}
                subtitle={t('access::account.password_note')}
                footerAction={
                    <Button
                        typeName="submit"
                        loading={form.processing}
                        disabledReason={repeat.differs ? differ : undefined}
                        data-test="save-password"
                    >
                        {t('access::account.save')}
                    </Button>
                }
            >
                <FormError />

                <PasswordInput
                    id="current_password"
                    name="current_password"
                    label={t('access::account.current_password')}
                    error={form.errors.current_password}
                    autoComplete="current-password"
                    required
                    value={form.data.current_password}
                    onChange={(event) => form.setData('current_password', event.target.value)}
                />

                <PasswordInput
                    id="password"
                    name="password"
                    label={t('access::account.new_password')}
                    helper={t('access::account.password_rule', { count: account.passwordMinLength })}
                    error={form.errors.password}
                    autoComplete="new-password"
                    required
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                />

                <PasswordInput
                    id="password_repeat"
                    name="password_repeat"
                    label={t('access::account.confirm_password')}
                    error={repeat.differs ? differ : undefined}
                    autoComplete="new-password"
                    required
                    value={repeat.value}
                    onChange={(event) => repeat.setValue(event.target.value)}
                />
            </Fieldset>

            <section className="material-base grid gap-1 p-5 sm:p-6">
                <h2 className="text-heading-20 text-ink">{t('access::account.two_factor_title')}</h2>
                <p className="text-copy-14 text-ink-muted">{t('access::account.two_factor_body')}</p>
            </section>
        </div>
    );
}
