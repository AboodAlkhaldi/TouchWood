import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/PasswordInput';
import { useTranslator } from '@/lib/t';

/*
| A1 - Sign in (frontend.md §3.1).
|
| Work email and password. No "keep me signed in": a session that outlives the person at the desk is
| exactly what the code step exists to prevent (§2.7).
|
| The answer never says whether the account exists - the same refusal for an unknown address, a wrong
| password and a disabled account - so this page holds no logic about which it was.
*/

export default function SignIn() {
    const t = useTranslator();
    const form = useForm({ email: '', password: '' });

    return (
        <SignInLayout title={t('access::auth.sign_in')} subtitle={t('access::auth.sign_in_subtitle')}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/admin/sign-in');
                }}
                className="grid gap-5"
            >
                <FormError />

                <Field id="email" label={t('access::auth.email')} error={form.errors.email}>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        autoComplete="username"
                        required
                        autoFocus
                        dir="ltr"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />
                </Field>

                <Field id="password" label={t('access::auth.password')} error={form.errors.password}>
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="current-password"
                        required
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                </Field>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {t('access::auth.sign_in')}
                </Button>

                <a href="/admin/password/forgot" className="text-sm text-brand hover:text-accent">
                    {t('access::auth.forgot_password')}
                </a>
            </form>
        </SignInLayout>
    );
}
