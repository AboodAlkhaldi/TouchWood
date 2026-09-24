import { useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ShopCard } from '@/components/ShopCard';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { PasswordInput } from '@/components/PasswordInput';
import { useRepeatedPassword } from '@/lib/passwords';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ResetPasswordPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F6, the second half - a new password from the link (frontend.md §3.6).
|
| The rule is shown in words before anyone types, and the number in it is the setting's, never one
| written here: the customer's own minimum, which is not the staff one (access.md §1.8).
|
| The second box is this page's to check (see lib/passwords), and nothing is sent while the two
| differ.
*/

type Props = ResetPasswordPage;

export default function ResetPassword({ token, minimumLength }: Props) {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ password: '' });
    const repeat = useRepeatedPassword(form.data.password);

    return (
        <StorefrontLayout title={t('access::auth.reset_title')}>
            <ShopCard title={t('access::auth.reset_title')}>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();

                        // The button is already out of reach while the two differ; this is the
                        // same rule again for a form sent by pressing Enter in a field.
                        if (repeat.differs) {
                            return;
                        }

                        form.post(link('storefront.account.reset-password', { token }));
                    }}
                    className="grid gap-5"
                >
                    <FormError />

                    <Field
                        id="password"
                        label={t('access::auth.new_password')}
                        hint={t('access::auth.password_rule', { count: minimumLength })}
                        error={form.errors.password}
                    >
                        <PasswordInput
                            id="password"
                            name="password"
                            autoComplete="new-password"
                            required
                            autoFocus
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="password_repeat"
                        label={t('access::auth.confirm_password')}
                        error={repeat.differs ? t('access::auth.passwords_differ') : undefined}
                    >
                        <PasswordInput
                            id="password_repeat"
                            name="password_repeat"
                            autoComplete="new-password"
                            required
                            value={repeat.value}
                            onChange={(event) => repeat.setValue(event.target.value)}
                        />
                    </Field>

                    <Button
                        type="submit"
                        data-test="save-password"
                        disabled={form.processing || repeat.differs}
                        className="w-full"
                    >
                        {t('access::auth.save_password')}
                    </Button>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
