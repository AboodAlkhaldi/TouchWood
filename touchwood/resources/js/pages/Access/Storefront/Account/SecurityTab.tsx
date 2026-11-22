import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { useRepeatedPassword } from '@/lib/passwords';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F8 - the password tab (frontend.md §3.6), one shadcn Card in Geist's Fieldset form (§1.11): the
| three fields, and in the footer the one button, which says what it does.
|
| Changing the password, and nothing else. A customer signs in with an email and a password alone -
| there is no code to their phone and so no switch for one (access.md §1.8), and saying nothing
| about it is better than a setting that was never there.
|
| The rule is the setting's, in words, before anyone types - not a message after they have chosen
| something we then refuse. The second box is the page's to check (see lib/passwords): while the two
| differ the button says why it is out of reach, in its tooltip, as Geist asks, and the message
| under the box waits until the person leaves it or tries to send.
|
| A refusal - a wrong current password - is said inside the card, under its title, not above the
| title of the section it belongs to.
*/

type Props = {
    account: CustomerAccountPage;
};

export function SecurityTab({ account }: Props) {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ current_password: '', password: '' });
    const repeat = useRepeatedPassword(form.data.password);
    const differ = t('access::account.passwords_differ');
    // Each password as typed (frontend.md §1.7), with the server's rules: both required
    // (OwnPasswordRequest) and never trimmed; the new one at least the customer's minimum
    // (LaravelPasswordPolicy) - the breach list is the server's alone. The second box keeps its own
    // check (lib/passwords).
    const checks = useChecks([
        { id: 'current_password', label: t('access::account.current_password'), value: form.data.current_password, rules: { required: true, keepSpaces: true } },
        {
            id: 'password',
            label: t('access::account.new_password'),
            value: form.data.password,
            rules: { required: true, keepSpaces: true, length: { min: account.passwordMinimumLength } },
        },
    ]);

    return (
        <Card className="material-base gap-0 border-0 py-0">
            <form
                onKeyDown={repeat.enter}
                onSubmit={(event) => {
                    event.preventDefault();
                    repeat.tried();

                    // The button refuses a press while the two differ, so a form sent by
                    // Enter stops there (the form's onKeyDown says why); this is the last word.
                    if (repeat.differs) {
                        return;
                    }

                    checks.submit(() =>
                        form.post(link('storefront.account.password.change'), {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                repeat.clear();
                            },
                        }),
                    );
                }}
            >
                <CardHeader className="px-6 pt-5 pb-4">
                    <CardTitle className="text-heading-20 text-ink">
                        <h2>{t('access::account.change_password')}</h2>
                    </CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.shop_password_note')}</CardDescription>
                </CardHeader>

                <CardContent className="px-6 pb-5">
                    <FieldGroup className="gap-5">
                        <FormError />

                        <PasswordInput
                            id="current_password"
                            name="current_password"
                            label={t('access::account.current_password')}
                            check={checks.box('current_password', form.errors.current_password)}
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
                            check={checks.box('password', form.errors.password)}
                            autoComplete="new-password"
                            required
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                        />

                        <PasswordInput
                            id="password_repeat"
                            name="password_repeat"
                            label={t('access::account.confirm_password')}
                            error={repeat.showDiffers ? differ : undefined}
                            autoComplete="new-password"
                            required
                            value={repeat.value}
                            onChange={(event) => repeat.setValue(event.target.value)}
                            onBlur={repeat.left}
                        />
                    </FieldGroup>
                </CardContent>

                <CardFooter className="justify-end border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                    <ActionButton type="submit" loading={form.processing} disabledReason={repeat.differs ? differ : checks.reason} data-test="save-password">
                        {t('access::account.change_password')}
                    </ActionButton>
                </CardFooter>
            </form>
        </Card>
    );
}
