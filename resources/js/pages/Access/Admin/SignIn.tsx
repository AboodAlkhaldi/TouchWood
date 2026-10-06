import { Link, useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Field, FieldGroup } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';

/*
| A1 - Sign in (frontend.md §3.1), on shadcn's `login-02` form as it writes it (§1.11): a
| FieldGroup of Fields, the reset link on the password's label row, the button in a Field of its own.
|
| Work email and password. No "keep me signed in": a session that outlives the person at the desk is
| exactly what the code step exists to prevent (§2.7).
|
| The answer never says whether the account exists - the same refusal for an unknown address, a wrong
| password and a disabled account - so this page holds no logic about which it was.
|
| While the form posts the button shows it is busy rather than greying out: it stays where it is,
| stays reachable, and ignores a second press (Geist's Button).
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
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    <TextField
                        id="email"
                        name="email"
                        type="email"
                        label={t('access::auth.email')}
                        error={form.errors.email}
                        autoComplete="username"
                        required
                        autoFocus
                        dir="ltr"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />

                    <PasswordInput
                        id="password"
                        name="password"
                        label={t('access::auth.password')}
                        labelEnd={
                            <Link href="/admin/password/forgot" className="text-copy-13 text-ink-muted underline-offset-4 hover:text-ink hover:underline">
                                {t('access::auth.forgot_password')}
                            </Link>
                        }
                        error={form.errors.password}
                        autoComplete="current-password"
                        required
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />

                    <Field>
                        <ActionButton type="submit" loading={form.processing} className="w-full">
                            {t('access::auth.sign_in')}
                        </ActionButton>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
