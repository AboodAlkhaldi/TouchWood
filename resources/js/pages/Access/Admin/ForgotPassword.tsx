import { Link, useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Field, FieldDescription, FieldGroup } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';

/*
| A4 - forgot password (frontend.md §3.1), on shadcn's login-02 form (§1.11): the way back to sign
| in is the block's centred FieldDescription under the button.
|
| The answer is the same whether or not the account exists, so this page cannot tell the person
| anything either: it says a link has been sent, and means it the same way both times.
*/

export default function ForgotPassword() {
    const t = useTranslator();
    const form = useForm({ email: '' });
    // The box as typed (frontend.md §1.7): required (EmailRequest), shaped as every staff address is
    // (EmailAddress). Whether an account has it is never said, here or by the server.
    const checks = useChecks([{ id: 'email', label: t('access::auth.email'), value: form.data.email, rules: { required: true, email: true } }]);

    return (
        <SignInLayout title={t('access::auth.forgot_title')} subtitle={t('access::auth.forgot_subtitle')}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    checks.submit(() => form.post('/admin/password/forgot'));
                }}
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    <TextField
                        id="email"
                        name="email"
                        type="email"
                        label={t('access::auth.email')}
                        check={checks.box('email', form.errors.email)}
                        autoComplete="username"
                        required
                        autoFocus
                        dir="ltr"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />

                    <Field>
                        <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} className="w-full">
                            {t('access::auth.send_link')}
                        </ActionButton>
                        <FieldDescription className="text-center">
                            <Link href="/admin/sign-in" className="underline underline-offset-4">
                                {t('access::auth.back_to_sign_in')}
                            </Link>
                        </FieldDescription>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
