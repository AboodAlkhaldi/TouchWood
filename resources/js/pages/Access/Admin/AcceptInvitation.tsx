import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Description } from '@/components/geist-only/Description';
import { PasswordInput } from '@/components/PasswordInput';
import { Field, FieldGroup } from '@/components/ui/field';
import { useRepeatedPassword } from '@/lib/passwords';
import { useTranslator } from '@/lib/t';
import type { InvitationPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A6 - accepting an invitation (frontend.md §3.1), on shadcn's login-02 form (§1.11).
|
| Their name and address are shown but cannot be changed here: an admin entered them, and changing
| an address would make the invitation a different invitation. The address is a fact, so it is
| Geist's Description, as on A8 - not a greyed field taken out of the keyboard's reach (the batch B
| audit). The phone the admin entered can be corrected, because it is the number the code is about
| to go to (Access amendment 15).
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
    const differ = t('access::auth.passwords_differ');

    return (
        <SignInLayout title={t('access::auth.invitation_title')} subtitle={t('access::auth.invitation_subtitle', { name })}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    repeat.tried();

                    // The button is already out of reach while the two differ; this is the same
                    // rule again for a form sent by pressing Enter in a field.
                    if (repeat.differs) {
                        return;
                    }

                    form.post(`/admin/invitation/${token}`);
                }}
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    <Description columns={1} items={[{ title: t('access::auth.email'), content: <bdi dir="ltr">{email}</bdi>, 'data-test': 'invited-email' }]} />

                    <TextField
                        id="phone"
                        name="phone"
                        type="tel"
                        label={t('access::auth.phone')}
                        helper={t('access::auth.phone_hint')}
                        error={form.errors.phone}
                        autoComplete="tel"
                        required
                        dir="ltr"
                        inputClassName="tw-figure"
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
                            data-test="accept-invitation"
                            loading={form.processing}
                            disabledReason={repeat.differs ? differ : undefined}
                            className="w-full"
                        >
                            {t('access::auth.accept_invitation')}
                        </ActionButton>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
