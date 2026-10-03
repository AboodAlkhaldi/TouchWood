import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { useRepeatedPassword } from '@/lib/passwords';
import { useTranslator } from '@/lib/t';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B3 - the security tab (frontend.md §3.2), on shadcn's Card with Geist's rules (§1.11).
|
| Changing the password, and nothing else. There is no two-factor switch, because there is no
| two-factor choice: every staff member signs in with a code to their phone, always (§2.7). The tab
| says so, in a card of its own, rather than leaving a person hunting for a setting that was never
| there.
|
| The rule is the setting's, in words, before anyone types - not a message after they have chosen
| something we then refuse.
|
| The password is a Card that is itself the form, its button in the footer naming what it does -
| Change Password, as the card's title says (Geist's Button: a label names what happens; the batch B
| audit). While the two new boxes differ that button is out of reach and says why; the message under
| the box waits until it is left (lib/passwords).
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
            <Card className="material-base gap-0 border-0 py-0">
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        repeat.tried();

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
                >
                    <CardHeader className="px-6 pt-5 pb-4">
                        <CardTitle className="text-heading-20 text-ink">
                            <h2>{t('access::account.change_password')}</h2>
                        </CardTitle>
                        <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.password_note')}</CardDescription>
                    </CardHeader>

                    <CardContent className="px-6 pb-5">
                        <FieldGroup className="gap-5">
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
                        <ActionButton type="submit" loading={form.processing} disabledReason={repeat.differs ? differ : undefined} data-test="save-password">
                            {t('access::account.change_password')}
                        </ActionButton>
                    </CardFooter>
                </form>
            </Card>

            {/* Geist's Fieldset without a footer: information, no action (the batch B audit). */}
            <Card className="material-base gap-1 border-0 py-5">
                <CardHeader className="px-6">
                    <CardTitle className="text-heading-20 text-ink">
                        <h2>{t('access::account.two_factor_title')}</h2>
                    </CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.two_factor_body')}</CardDescription>
                </CardHeader>
            </Card>
        </div>
    );
}
