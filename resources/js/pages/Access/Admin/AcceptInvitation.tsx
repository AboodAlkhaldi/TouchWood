import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/PasswordInput';
import { useRepeatedPassword } from '@/lib/passwords';
import { useTranslator } from '@/lib/t';
import type { InvitationPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A6 - accepting an invitation (frontend.md §3.1).
|
| Their name and address are shown but cannot be changed here: an admin entered them, and changing
| an address would make the invitation a different invitation. The phone the admin entered can be
| corrected, because it is the number the code is about to go to (Access amendment 15).
|
| Opening the link changes nothing. Nothing happens until this form is sent.
*/

type Props = InvitationPage;

export default function AcceptInvitation({ token, name, email, phone, minimumLength }: Props) {
    const t = useTranslator();
    const form = useForm({ phone, password: '' });
    // The second box is this page's to check (see lib/passwords): the endpoint takes `password`
    // alone, so a typo in the box nobody can read would set a password they did not mean - on an
    // account they have not signed in to yet.
    const repeat = useRepeatedPassword(form.data.password);

    return (
        <SignInLayout
            title={t('access::auth.invitation_title')}
            subtitle={t('access::auth.invitation_subtitle', { name })}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();

                    // The button is already out of reach while the two differ; this is the same
                    // rule again for a form sent by pressing Enter in a field.
                    if (repeat.differs) {
                        return;
                    }

                    form.post(`/admin/invitation/${token}`);
                }}
                className="grid gap-5"
            >
                <FormError />

                <Field id="email" label={t('access::auth.email')}>
                    <Input id="email" type="email" value={email} readOnly disabled dir="ltr" />
                </Field>

                <Field
                    id="phone"
                    label={t('access::auth.phone')}
                    hint={t('access::auth.phone_hint')}
                    error={form.errors.phone}
                >
                    <Input
                        id="phone"
                        name="phone"
                        type="tel"
                        autoComplete="tel"
                        required
                        dir="ltr"
                        className="tw-figure"
                        value={form.data.phone}
                        onChange={(event) => form.setData('phone', event.target.value)}
                    />
                </Field>

                <Field
                    id="password"
                    label={t('access::auth.password')}
                    hint={t('access::auth.password_rule', { count: minimumLength })}
                    error={form.errors.password}
                >
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        required
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
                    data-test="accept-invitation"
                    disabled={form.processing || repeat.differs}
                    className="w-full"
                >
                    {t('access::auth.accept_invitation')}
                </Button>
            </form>
        </SignInLayout>
    );
}
