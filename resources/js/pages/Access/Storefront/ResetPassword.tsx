import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ShopCard } from '@/components/ShopCard';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { PasswordInput } from '@/components/PasswordInput';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ResetPasswordPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F6, the second half - a new password from the link (frontend.md §3.6).
|
| The rule is shown in words before anyone types, and the number in it is the setting's, never one
| written here: the customer's own minimum, which is not the staff one (access.md §1.8).
|
| **The repeat box is checked here and nowhere else.** The endpoint takes `password` alone, and has
| since it was published - it is what the reset link's own tests send - so the repeat is this
| page's courtesy to the person typing, not a rule of the system. Sending them a password they did
| not mean, silently, because they mistyped the second box, is the thing worth preventing.
*/

type Props = ResetPasswordPage;

export default function ResetPassword({ token, minimumLength }: Props) {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ password: '', password_confirmation: '' });
    const [repeated, setRepeated] = useState(true);

    return (
        <StorefrontLayout title={t('access::auth.reset_title')}>
            <ShopCard title={t('access::auth.reset_title')}>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();

                        const matches = form.data.password === form.data.password_confirmation;
                        setRepeated(matches);

                        if (matches) {
                            form.post(link('storefront.account.reset-password', { token }));
                        }
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
                        id="password_confirmation"
                        label={t('access::auth.confirm_password')}
                        error={repeated ? undefined : t('access::auth.passwords_differ')}
                    >
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            autoComplete="new-password"
                            required
                            value={form.data.password_confirmation}
                            onChange={(event) => {
                                form.setData('password_confirmation', event.target.value);
                                setRepeated(true);
                            }}
                        />
                    </Field>

                    <Button type="submit" disabled={form.processing} className="w-full">
                        {t('access::auth.save_password')}
                    </Button>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
