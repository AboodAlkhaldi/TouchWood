import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Field, FieldGroup } from '@/components/ui/field';
import { useRepeatedPassword } from '@/lib/passwords';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ResetPasswordPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A5 - a new password (frontend.md §3.1), on shadcn's login-02 form (§1.11).
|
| The rule is shown in words before anyone types, and the number in it is the setting's, never a
| number written here. Afterwards the person signs in again, code and all: a reset proves the
| address, not the phone.
|
| The second box is this page's to check (see lib/passwords): the endpoint takes `password` alone,
| so a typo in the box nobody can read would save a password the person did not mean. While the two
| differ the button is out of reach and says why; the message under the box waits until the person
| leaves it or tries to send (Geist: validate on blur, not on every keystroke).
*/

type Props = ResetPasswordPage;

export default function ResetPassword({ token, minimumLength }: Props) {
    const t = useTranslator();
    const form = useForm({ password: '' });
    const repeat = useRepeatedPassword(form.data.password);
    const differ = t('access::auth.passwords_differ');
    // The new password as typed (frontend.md §1.7): at least the setting's length
    // (StaffSecuritySettings::PASSWORD_MIN_LENGTH, as PasswordPolicy::hashNew counts it), the number
    // its helper names (`minimumLength`), its spaces kept as typed. Whether it is on a breach list
    // is the server's alone to know.
    const checks = useChecks([
        { id: 'password', label: t('access::auth.new_password'), value: form.data.password, rules: { required: true, keepSpaces: true, length: { min: minimumLength } } },
    ]);

    return (
        <SignInLayout title={t('access::auth.reset_title')}>
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

                    checks.submit(() => form.post(`/admin/password/reset/${token}`));
                }}
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    <PasswordInput
                        id="password"
                        name="password"
                        label={t('access::auth.new_password')}
                        helper={t('access::auth.password_rule', { count: minimumLength })}
                        check={checks.box('password', form.errors.password)}
                        autoComplete="new-password"
                        required
                        autoFocus
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />

                    <PasswordInput
                        id="password_repeat"
                        name="password_repeat"
                        label={t('access::auth.confirm_password')}
                        error={repeat.showDiffers ? differ : undefined}
                        autoComplete="new-password"
                        required
                        value={repeat.value}
                        onChange={(event) => repeat.setValue(event.target.value)}
                        onBlur={repeat.left}
                    />

                    <Field>
                        <ActionButton
                            type="submit"
                            data-test="save-password"
                            loading={form.processing}
                            disabledReason={(repeat.differs ? differ : undefined) ?? checks.reason}
                            className="w-full"
                        >
                            {t('access::auth.save_password')}
                        </ActionButton>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
