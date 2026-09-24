import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { PasswordInput } from '@/components/PasswordInput';
import { useTranslator } from '@/lib/t';
import type { ResetPasswordPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A5 - a new password (frontend.md §3.1).
|
| The rule is shown in words before anyone types, and the number in it is the setting's, never a
| number written here. Afterwards the person signs in again, code and all: a reset proves the
| address, not the phone.
*/

type Props = ResetPasswordPage;

export default function ResetPassword({ token, minimumLength }: Props) {
    const t = useTranslator();
    const form = useForm({ password: '', password_confirmation: '' });

    return (
        <SignInLayout title={t('access::auth.reset_title')}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/admin/password/reset/${token}`);
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
                    error={form.errors.password_confirmation}
                >
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData('password_confirmation', event.target.value)
                        }
                    />
                </Field>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {t('access::auth.save_password')}
                </Button>
            </form>
        </SignInLayout>
    );
}
