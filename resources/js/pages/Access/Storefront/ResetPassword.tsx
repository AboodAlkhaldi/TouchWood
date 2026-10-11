import { useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { ShopCard } from '@/components/ShopCard';
import { Field, FieldGroup } from '@/components/ui/field';
import { useRepeatedPassword } from '@/lib/passwords';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ResetPasswordPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F6, the second half - a new password from the link (frontend.md §3.6), on shadcn's `signup-01`
| form, its password and repeat fields (§1.11).
|
| The rule is shown in words before anyone types, and the number in it is the setting's, never one
| written here: the customer's own minimum, which is not the staff one (access.md §1.8).
|
| The second box is this page's to check (see lib/passwords), and nothing is sent while the two
| differ. The button says why it is out of reach, in its tooltip, as Geist asks of every disabled
| button; the message under the box waits until the person leaves it or tries to send (Geist:
| validate on blur, not on every keystroke).
*/

type Props = ResetPasswordPage;

export default function ResetPassword({ token, minimumLength }: Props) {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ password: '' });
    const repeat = useRepeatedPassword(form.data.password);
    const differ = t('access::auth.passwords_differ');
    // The new password as typed (frontend.md §1.7), with the server's rules: required
    // (NewPasswordRequest), at least the customer's minimum and never trimmed (LaravelPasswordPolicy) -
    // the breach list is the server's alone. The second box keeps its own check (lib/passwords).
    const checks = useChecks([
        { id: 'password', label: t('access::auth.new_password'), value: form.data.password, rules: { required: true, keepSpaces: true, length: { min: minimumLength } } },
    ]);

    return (
        <StorefrontLayout title={t('access::auth.reset_title')}>
            <ShopCard title={t('access::auth.reset_title')}>
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

                        checks.submit(() => form.post(link('storefront.account.reset-password', { token })));
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
                                disabledReason={repeat.differs ? differ : checks.reason}
                                className="w-full"
                            >
                                {t('access::auth.save_password')}
                            </ActionButton>
                        </Field>
                    </FieldGroup>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
