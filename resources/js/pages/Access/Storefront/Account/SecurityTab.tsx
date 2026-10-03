import { useForm } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button } from '@/components/geist';
import { useRepeatedPassword } from '@/lib/passwords';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F8 - the password tab (frontend.md §3.6), on Geist's fields and button (1.10).
|
| Changing the password, and nothing else. A customer signs in with an email and a password alone -
| there is no code to their phone and so no switch for one (access.md §1.8), and saying nothing
| about it is better than a setting that was never there.
|
| The rule is the setting's, in words, before anyone types - not a message after they have chosen
| something we then refuse. The second box is the page's to check (see lib/passwords), and while
| the two differ the button says why it is out of reach, in its tooltip, as Geist asks.
*/

type Props = {
    account: CustomerAccountPage;
};

export function SecurityTab({ account }: Props) {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ current_password: '', password: '' });
    const repeat = useRepeatedPassword(form.data.password);
    const differ = repeat.differs ? t('access::account.passwords_differ') : undefined;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();

                // The button is already out of reach while the two differ; this is the same rule
                // again for a form sent by pressing Enter in a field.
                if (repeat.differs) {
                    return;
                }

                form.post(link('storefront.account.password.change'), {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        repeat.clear();
                    },
                });
            }}
            className="grid gap-5"
        >
            <FormError />

            <div className="grid gap-1">
                <h2 className="text-heading-16 text-ink">{t('access::account.change_password')}</h2>
                <p className="text-copy-13 text-ink-muted">{t('access::account.shop_password_note')}</p>
            </div>

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
                helper={t('access::account.password_rule', { count: account.passwordMinimumLength })}
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
                error={differ}
                autoComplete="new-password"
                required
                value={repeat.value}
                onChange={(event) => repeat.setValue(event.target.value)}
            />

            <Button
                typeName="submit"
                loading={form.processing}
                disabledReason={differ}
                data-test="save-password"
                className="w-fit"
            >
                {t('access::account.save')}
            </Button>
        </form>
    );
}
