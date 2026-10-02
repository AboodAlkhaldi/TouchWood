import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { FormError } from '@/components/FormError';
import { Button, Input } from '@/components/geist';
import { PasswordInput } from '@/components/PasswordInput';
import { useRepeatedPassword } from '@/lib/passwords';
import { useTranslator } from '@/lib/t';
import type { InvitationPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A6 - accepting an invitation (frontend.md §3.1), in Geist (1.10).
|
| Their name and address are shown but cannot be changed here: an admin entered them, and changing
| an address would make the invitation a different invitation. The phone the admin entered can be
| corrected, because it is the number the code is about to go to (Access amendment 15).
|
| Opening the link changes nothing. Nothing happens until this form is sent.
*/

type Props = InvitationPage;

/**
 * The figure face (§1.8) on the box inside Geist's Input. The Input takes its class on the field as
 * a whole - label and helper included - so `tw-figure` there would set the label in the mono face
 * too. This is the same rule aimed at the box alone: mono with even digits, and the Arabic face on
 * an Arabic page, exactly as `tw-figure` does.
 */
const FIGURES = '[&_input]:font-mono [&_input]:tabular-nums [[lang=ar]_&_input]:font-sans';

export default function AcceptInvitation({ token, name, email, phone, minimumLength }: Props) {
    const t = useTranslator();
    const form = useForm({ phone, password: '' });
    // The second box is this page's to check (see lib/passwords): the endpoint takes `password`
    // alone, so a typo in the box nobody can read would set a password they did not mean - on an
    // account they have not signed in to yet.
    const repeat = useRepeatedPassword(form.data.password);
    const differ = t('access::auth.passwords_differ');

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

                <Input
                    id="email"
                    type="email"
                    label={t('access::auth.email')}
                    value={email}
                    readOnly
                    disabled
                    dir="ltr"
                />

                <Input
                    id="phone"
                    name="phone"
                    type="tel"
                    label={t('access::auth.phone')}
                    helper={t('access::auth.phone_hint')}
                    error={form.errors.phone}
                    autoComplete="tel"
                    required
                    dir="ltr"
                    className={FIGURES}
                    value={form.data.phone}
                    onChange={(event) => form.setData('phone', event.target.value)}
                />

                <PasswordInput
                    id="password"
                    name="password"
                    label={t('access::auth.password')}
                    helper={t('access::auth.password_rule', { count: minimumLength })}
                    error={form.errors.password}
                    autoComplete="new-password"
                    required
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                />

                <PasswordInput
                    id="password_repeat"
                    name="password_repeat"
                    label={t('access::auth.confirm_password')}
                    error={repeat.differs ? differ : undefined}
                    autoComplete="new-password"
                    required
                    value={repeat.value}
                    onChange={(event) => repeat.setValue(event.target.value)}
                />

                <Button
                    typeName="submit"
                    data-test="accept-invitation"
                    loading={form.processing}
                    disabledReason={repeat.differs ? differ : undefined}
                    className="w-full"
                >
                    {t('access::auth.accept_invitation')}
                </Button>
            </form>
        </SignInLayout>
    );
}
